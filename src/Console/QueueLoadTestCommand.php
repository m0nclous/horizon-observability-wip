<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use SushiMarket\HorizonPrometheusGrafana\HorizonQueues;
use SushiMarket\HorizonPrometheusGrafana\Jobs\QueueLoadTestJob;

/**
 * Временная: нагружает очереди тестовыми задачами, чтобы проверить дашборд «Queues» на QA.
 * На удаление вместе с QueueLoadTestJob, см. docs/cleanup.md.
 *
 * Сначала в очередь ставится по прогревочному джобу каждой задачи, нагрузка начинается через --warmup секунд.
 * Счётчик новой задачи Prometheus впервые видит уже ненулевым, и increase() не считает
 * попытки до первого сбора: без прогрева быстрые задачи могли бы целиком выпасть из «Выполнено».
 *
 * С --continuous работает, пока не остановят (Ctrl+C или SIGTERM): поток джобов, похожий на рабочий,
 * с волнами, всплесками и перегрузками, см. runContinuous().
 */
class QueueLoadTestCommand extends Command
{
    /**
     * Очередь => задача => [джобов, средняя длительность в секундах, провалов %, повторов %, таймаутов,
     * исчерпаний %]. Повтор — падение на первой попытке и успех на второй. Исчерпание — падение на всех трёх
     * попытках: два повтора и провал, в Failed Jobs — Attempts 3. Каждый таймаут убивает воркер.
     */
    private const SCENARIO = [
        'default' => [
            'TestPingJob' => [200, 0.05, 0, 0, 0, 0],
            'TestSendSmsJob' => [120, 0.2, 2, 5, 0, 0],
            'TestPushNotificationJob' => [100, 0.3, 0, 10, 0, 0],
            'TestOrderSyncJob' => [80, 0.8, 5, 5, 0, 3],
            'TestPaymentCheckJob' => [60, 0.5, 10, 0, 0, 0],
            'TestWebhookJob' => [60, 0.4, 0, 30, 0, 5],
            'TestGeocodeJob' => [50, 1, 20, 0, 0, 0],
            'TestCacheWarmupJob' => [40, 1.5, 0, 0, 0, 0],
            'TestImageResizeJob' => [20, 3, 0, 0, 1, 0],
            'TestBrokenJob' => [10, 0.2, 100, 0, 0, 0],
        ],
        'slow' => [
            'TestCleanupJob' => [30, 1, 0, 0, 0, 0],
            'TestCompressJob' => [25, 2, 0, 0, 1, 0],
            'TestImportJob' => [20, 4, 0, 0, 0, 0],
            'TestExportJob' => [15, 6, 5, 0, 0, 0],
            'TestRecalculateJob' => [10, 8, 0, 10, 0, 0],
        ],
        'slow_reports' => [
            'TestSalesReportJob' => [8, 5, 0, 0, 0, 0],
            'TestDailyReportJob' => [6, 10, 0, 0, 0, 0],
            'TestFinanceReportJob' => [4, 15, 25, 0, 0, 0],
        ],
    ];

    /**
     * Тестовые батчи: [имя, задача, джобов, средняя длительность, провалов, allowFailures, очередь].
     * Числа и uuid в имени — как у настоящих батчей, на дашборде они отбрасываются. С провалом без
     * allowFailures батч отменяется, с allowFailures — завершается с упавшими джобами.
     */
    private const BATCHES = [
        ['test_import_%d', 'TestBatchImportJob', 8, 2, 0, false, 'slow'],
        ['test_report_%s', 'TestBatchReportJob', 6, 3, 1, true, 'slow_reports'],
        ['test_sync_%d', 'TestBatchSyncJob', 6, 1.5, 1, false, 'slow'],
    ];

    /**
     * Непрерывная нагрузка: очередь => задача => джобов в минуту в среднем. Длительность, провалы, повторы
     * и таймауты — из SCENARIO. В default это около 1–2 занятых воркеров, в slow и slow_reports — доли воркера
     */
    private const RATES = [
        'default' => [
            'TestPingJob' => 120,
            'TestSendSmsJob' => 40,
            'TestPushNotificationJob' => 30,
            'TestOrderSyncJob' => 20,
            'TestPaymentCheckJob' => 15,
            'TestWebhookJob' => 25,
            'TestGeocodeJob' => 10,
            'TestCacheWarmupJob' => 4,
            'TestImageResizeJob' => 2,
            'TestBrokenJob' => 0.5,
        ],
        'slow' => [
            'TestCleanupJob' => 4,
            'TestCompressJob' => 3,
            'TestImportJob' => 1.5,
            'TestExportJob' => 1,
            'TestRecalculateJob' => 0.5,
        ],
        'slow_reports' => [
            'TestSalesReportJob' => 0.5,
            'TestDailyReportJob' => 0.3,
            'TestFinanceReportJob' => 0.2,
        ],
    ];

    // Темп каждой задачи плавно меняется на ±50% с периодом 20 минут, у каждой со своим сдвигом
    private const WAVE_PERIOD_SECONDS = 1200;
    private const WAVE_AMPLITUDE = 0.5;

    // Всплеск: пачка 30–80 джобов одной частой задачи default разом, как при рассылке
    private const BURST_EVERY_SECONDS = [300, 720];
    private const BURST_SIZE = [30, 80];

    // Перегрузка: темп одной очереди в 6–10 раз выше на 1–3 минуты, а в начале — разом 30 её джобов.
    // Без этого сброса slow и slow_reports с редкими длинными джобами не дотягивали бы до двух лимитов воркеров
    private const OVERLOAD_EVERY_SECONDS = [900, 1800];
    private const OVERLOAD_SECONDS = [60, 180];
    private const OVERLOAD_FACTOR = [6, 10];
    private const OVERLOAD_DUMP = 30;

    /**
     * Батчи непрерывной нагрузки: крупнее, чем в BATCHES, чтобы шли 1–2 минуты и на дашборде был виден
     * прогресс. Каждый разом кладёт в очередь сотни джобов — это тоже всплеск, поэтому не чаще раза в 4–8 минут.
     * [имя, задача, джобов от и до, средняя длительность, провалов, allowFailures, вес выбора, очередь]
     */
    private const CONTINUOUS_BATCHES = [
        ['test_import_%d', 'TestBatchImportJob', [150, 300], 3, 0, false, 5, 'slow'],
        ['test_report_%s', 'TestBatchReportJob', [60, 120], 4, 1, true, 3, 'slow_reports'],
        ['test_sync_%d', 'TestBatchSyncJob', [40, 60], 2, 1, false, 2, 'slow'],
    ];
    private const BATCH_EVERY_SECONDS = [240, 480];

    // Таймаут джобов батча: учёт батча в медленной БД занимает больше самой работы, см. QueueLoadTestJob
    private const BATCH_JOB_TIMEOUT_SECONDS = 60;

    // Каждый провал пишет строку в failed_jobs общей БД, поэтому в непрерывной нагрузке их втрое меньше
    private const CONTINUOUS_FAIL_FACTOR = 0.3;

    // Доля таймаутов у задач, у которых в SCENARIO они есть
    private const TIMEOUT_PERCENT = 1;

    protected $signature = 'queue-metrics:load-test
        {--queue=* : Очередь из сценария, можно несколько; по умолчанию default, slow и slow_reports}
        {--scale=1 : Множитель числа джобов каждой задачи}
        {--spread=120 : За сколько секунд равномерно распределить джобы}
        {--warmup=60 : Через сколько секунд после прогревочных джобов начать нагрузку; 0 — без прогрева}
        {--batches=1 : Тестовых батчей каждого вида: test_report — в slow_reports, остальные — в slow; 0 — без}
        {--continuous : Непрерывная нагрузка, похожая на рабочую, до остановки; --scale умножает темп}';

    protected $description = 'Временная: нагрузка очередей тестовыми задачами для проверки дашборда «Queues»';

    public function handle(HorizonQueues $horizonQueues): int
    {
        if (app()->isProduction()) {
            $this->error('В production команда не запускается');

            return self::FAILURE;
        }

        $queues = $this->option('queue') ?: array_keys(self::SCENARIO);
        $unknown = array_diff($queues, array_keys(self::SCENARIO));

        if ($unknown !== []) {
            $this->error('В сценарии нет очередей: ' . implode(', ', $unknown));

            return self::FAILURE;
        }

        $connection = (string) config('queue.default');
        $idle = array_diff($queues, $horizonQueues->byConnection()[$connection] ?? []);

        if ($idle !== []) {
            $this->warn('Эти очереди Horizon не обрабатывает, джобы останутся в них: ' . implode(', ', $idle));
        }

        $scale = max(0.0, (float) $this->option('scale'));

        if ($this->option('continuous')) {
            return $this->runContinuous($queues, $scale, $connection, (int) $this->option('batches') > 0);
        }

        $spread = max(0, (int) $this->option('spread'));
        $warmup = max(0, (int) $this->option('warmup'));
        $rows = [];

        foreach ($queues as $queue) {
            $jobs = [];

            foreach (self::SCENARIO[$queue] as $name => $task) {
                [$count, $duration, $failPercent, $retryPercent, $timeouts, $exhaustPercent] = $task;
                $count = (int) round($count * $scale);
                $timedOut = min($count, $timeouts);
                $failed = min($count - $timedOut, (int) round($count * $failPercent / 100));
                $exhausted = min($count - $timedOut - $failed, (int) round($count * $exhaustPercent / 100));
                $retried = min($count - $timedOut - $failed - $exhausted, (int) round($count * $retryPercent / 100));

                $jobs = [
                    ...$jobs,
                    ...$this->jobs($name, QueueLoadTestJob::OUTCOME_TIMED_OUT, $timedOut, $duration),
                    ...$this->jobs($name, QueueLoadTestJob::OUTCOME_FAILED, $failed, $duration),
                    ...$this->jobs($name, QueueLoadTestJob::OUTCOME_EXHAUSTED, $exhausted, $duration),
                    ...$this->jobs($name, QueueLoadTestJob::OUTCOME_RETRIED, $retried, $duration),
                    ...$this->jobs(
                        $name,
                        QueueLoadTestJob::OUTCOME_COMPLETED,
                        $count - $timedOut - $failed - $exhausted - $retried,
                        $duration,
                    ),
                ];

                if ($warmup > 0) {
                    QueueLoadTestJob::dispatch($name, QueueLoadTestJob::OUTCOME_COMPLETED, 0.0)
                        ->onConnection($connection)
                        ->onQueue($queue);
                }

                $rows[] = [
                    $queue, $name, $count, $count - $failed - $exhausted - $timedOut, $retried, $exhausted, $failed,
                    $timedOut,
                ];
            }

            // Задачи вперемешку, чтобы в каждый момент нагрузки в очереди были разные
            shuffle($jobs);

            foreach ($jobs as $index => $job) {
                dispatch($job->onConnection($connection)->onQueue($queue)->delay(
                    $warmup + intdiv($spread * $index, max(1, count($jobs))),
                ));
            }
        }

        $this->table(
            ['Очередь', 'Задача', 'Джобов', 'Выполнятся', 'Повторятся', 'Исчерпают попытки', 'Провалятся', 'Таймаутов'],
            $rows,
        );

        $batches = max(0, (int) $this->option('batches'));

        if ($batches > 0) {
            $this->dispatchBatches($batches, $connection);
            $this->info(sprintf(
                'Батчей: по %d вида test_import в slow (без ошибок), test_report в slow_reports (с упавшим джобом, '
                . 'allowFailures) и test_sync в slow (с упавшим джобом — будет отменён).',
                $batches,
            ));
        }

        $this->info(sprintf(
            'Нагрузка начнётся через %d с и продлится около %d с. «Ошибок» на дашборде — провалы плюс таймауты, '
            . 'повторяющиеся джобы выполнятся со второй попытки.%s',
            $warmup,
            $spread,
            $warmup > 0 ? ' Прогревочные джобы добавят по одному к «Выполнятся».' : '',
        ));
        $this->line('Числа на дашборде — оценка increase() в Prometheus: после короткой нагрузки они бывают '
            . 'выше на несколько процентов.');

        if ($warmup > 0) {
            $this->line('Повторный запуск тех же задач можно без прогрева: --warmup=0');
        }

        return self::SUCCESS;
    }

    /**
     * Поток джобов до остановки. Каждую секунду по каждой задаче ставится случайное число джобов
     * (распределение Пуассона) со средним по RATES, умноженным на волну и на перегрузку, если она идёт.
     * Всплески, перегрузки и батчи наступают через случайные промежутки.
     *
     * @param list<string> $queues
     */
    private function runContinuous(array $queues, float $scale, string $connection, bool $withBatches): int
    {
        $running = true;
        $this->trap([SIGINT, SIGTERM], function () use (&$running): void {
            $running = false;
        });

        $started = time();
        $phases = [];
        $nextBurst = $started + $this->between(self::BURST_EVERY_SECONDS);
        $nextOverload = $started + $this->between(self::OVERLOAD_EVERY_SECONDS);
        // Первый батч — сразу, чтобы таблица батчей не пустовала первые минуты
        $nextBatch = $started;
        $overload = null;
        $dispatched = 0;
        $reportedAt = $started;

        $this->info(sprintf(
            'Непрерывная нагрузка на %s, темп ×%s. Остановка: Ctrl+C или SIGTERM',
            implode(', ', $queues),
            $scale,
        ));

        while ($running) {
            $tick = microtime(true);
            $now = time();

            if ($overload !== null && $now >= $overload['until']) {
                $this->line(sprintf('[%s] перегрузка %s закончилась', date('H:i:s'), $overload['queue']));
                $overload = null;
            }

            if ($overload === null && $now >= $nextOverload) {
                $overload = [
                    'queue' => $queues[array_rand($queues)],
                    'factor' => $this->between(self::OVERLOAD_FACTOR),
                    'until' => $now + $this->between(self::OVERLOAD_SECONDS),
                ];
                $nextOverload = $overload['until'] + $this->between(self::OVERLOAD_EVERY_SECONDS);

                for ($i = 0; $i < self::OVERLOAD_DUMP; $i++) {
                    $task = $this->weightedTask($overload['queue']);
                    $dispatched += $this->dispatchStream($overload['queue'], $task, 1, $connection);
                }

                $this->line(sprintf(
                    '[%s] перегрузка %s: темп ×%d на %d с',
                    date('H:i:s'),
                    $overload['queue'],
                    $overload['factor'],
                    $overload['until'] - $now,
                ));
            }

            foreach ($queues as $queue) {
                $factor = $scale * ($overload !== null && $overload['queue'] === $queue ? $overload['factor'] : 1);

                foreach (self::RATES[$queue] as $task => $perMinute) {
                    $phases[$task] ??= mt_rand() / mt_getrandmax() * 2 * M_PI;
                    $angle = 2 * M_PI * ($now - $started) / self::WAVE_PERIOD_SECONDS + $phases[$task];
                    $count = $this->poisson($perMinute / 60 * $factor * (1 + self::WAVE_AMPLITUDE * sin($angle)));
                    $dispatched += $this->dispatchStream($queue, $task, $count, $connection);
                }
            }

            if ($now >= $nextBurst && in_array('default', $queues, true)) {
                $task = array_rand(array_slice(self::RATES['default'], 0, 6, true));
                $size = $this->between(self::BURST_SIZE);
                $dispatched += $this->dispatchStream('default', $task, $size, $connection);
                $nextBurst = $now + $this->between(self::BURST_EVERY_SECONDS);
                $this->line(sprintf('[%s] всплеск: %d × %s', date('H:i:s'), $size, $task));
            }

            if ($withBatches && $now >= $nextBatch) {
                [$name, $task, $size, $duration, $failed, $allowFailures, , $queue] = $this->weightedBatch();
                $size = $this->between($size);
                $name = $this->dispatchBatch(
                    [$name, $task, $size, $duration, $failed, $allowFailures, $queue],
                    $connection,
                );
                $nextBatch = $now + $this->between(self::BATCH_EVERY_SECONDS);
                $this->line(sprintf('[%s] батч %s: %d джобов', date('H:i:s'), $name, $size));
            }

            if ($now - $reportedAt >= 60) {
                $this->line(sprintf('[%s] за минуту поставлено джобов: %d', date('H:i:s'), $dispatched));
                $dispatched = 0;
                $reportedAt = $now;
            }

            usleep(max(0, 1_000_000 - (int) ((microtime(true) - $tick) * 1_000_000)));
        }

        $this->info('Нагрузка остановлена');

        return self::SUCCESS;
    }

    private function dispatchStream(string $queue, string $task, int $count, string $connection): int
    {
        [, $duration, $failPercent, $retryPercent, $timeouts, $exhaustPercent] = self::SCENARIO[$queue][$task];

        // Пороги броска: таймаут, провал, исчерпание попыток, повтор, иначе — выполнится
        $timedOut = $timeouts > 0 ? self::TIMEOUT_PERCENT : 0;
        $failed = $timedOut + $failPercent * self::CONTINUOUS_FAIL_FACTOR;
        $exhausted = $failed + $exhaustPercent * self::CONTINUOUS_FAIL_FACTOR;
        $retried = $exhausted + $retryPercent;

        for ($i = 0; $i < $count; $i++) {
            $roll = mt_rand() / mt_getrandmax() * 100;
            $outcome = match (true) {
                $roll < $timedOut => QueueLoadTestJob::OUTCOME_TIMED_OUT,
                $roll < $failed => QueueLoadTestJob::OUTCOME_FAILED,
                $roll < $exhausted => QueueLoadTestJob::OUTCOME_EXHAUSTED,
                $roll < $retried => QueueLoadTestJob::OUTCOME_RETRIED,
                default => QueueLoadTestJob::OUTCOME_COMPLETED,
            };

            dispatch((new QueueLoadTestJob($task, $outcome, $duration))->onConnection($connection)->onQueue($queue));
        }

        return $count;
    }

    private function dispatchBatches(int $count, string $connection): void
    {
        foreach (self::BATCHES as $spec) {
            for ($i = 1; $i <= $count; $i++) {
                $this->dispatchBatch($spec, $connection);
            }
        }
    }

    /**
     * Ставит батч и возвращает его имя.
     *
     * @param array{0: string, 1: string, 2: int, 3: float|int, 4: int, 5: bool, 6: string} $spec
     */
    private function dispatchBatch(array $spec, string $connection): string
    {
        [$name, $task, $size, $duration, $failed, $allowFailures, $queue] = $spec;
        $timeout = self::BATCH_JOB_TIMEOUT_SECONDS;
        $jobs = [
            ...$this->jobs($task, QueueLoadTestJob::OUTCOME_FAILED, $failed, $duration, $timeout),
            ...$this->jobs($task, QueueLoadTestJob::OUTCOME_COMPLETED, $size - $failed, $duration, $timeout),
        ];
        // Упавший джоб — не первым, чтобы батч успел пройти часть до отмены или ошибки
        shuffle($jobs);
        $name = sprintf($name, str_contains($name, '%s') ? Str::uuid()->toString() : random_int(1, 9999));

        Bus::batch($jobs)
            ->name($name)
            ->allowFailures($allowFailures)
            ->onConnection($connection)
            ->onQueue($queue)
            ->dispatch();

        return $name;
    }

    /**
     * Вид батча непрерывной нагрузки: чаще успешные, реже с упавшими джобами и отменяемые.
     *
     * @return array{0: string, 1: string, 2: array{0: int, 1: int}, 3: float|int, 4: int, 5: bool, 6: int, 7: string}
     */
    private function weightedBatch(): array
    {
        $roll = random_int(1, array_sum(array_column(self::CONTINUOUS_BATCHES, 6)));

        foreach (self::CONTINUOUS_BATCHES as $spec) {
            if (($roll -= $spec[6]) <= 0) {
                return $spec;
            }
        }

        return self::CONTINUOUS_BATCHES[0];
    }

    // Случайная задача очереди: частые выпадают чаще, пропорционально темпу из RATES
    private function weightedTask(string $queue): string
    {
        $roll = mt_rand() / mt_getrandmax() * array_sum(self::RATES[$queue]);

        foreach (self::RATES[$queue] as $task => $perMinute) {
            if (($roll -= $perMinute) <= 0) {
                return $task;
            }
        }

        return (string) array_key_last(self::RATES[$queue]);
    }

    /**
     * Случайное число по распределению Пуассона со средним $mean (алгоритм Кнута)
     */
    private function poisson(float $mean): int
    {
        $limit = exp(-$mean);
        $count = 0;
        $product = mt_rand() / mt_getrandmax();

        while ($product > $limit) {
            $count++;
            $product *= mt_rand() / mt_getrandmax();
        }

        return $count;
    }

    /**
     * @param array{0: int, 1: int} $range
     */
    private function between(array $range): int
    {
        return random_int($range[0], $range[1]);
    }

    /**
     * @return list<QueueLoadTestJob>
     */
    private function jobs(string $name, string $outcome, int $count, float $duration, ?int $timeout = null): array
    {
        $jobs = [];

        for ($i = 0; $i < $count; $i++) {
            $jobs[] = new QueueLoadTestJob($name, $outcome, $duration, $timeout);
        }

        return $jobs;
    }
}
