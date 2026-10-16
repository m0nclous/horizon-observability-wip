<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Хранилище метрик попыток выполнения джобов в Redis.
 *
 * Пишут воркеры Horizon, а читает /metrics в контейнере приложения, поэтому значения
 * лежат в общем Redis. Попытка пишется одним пайплайном атомарных HINCRBY/HINCRBYFLOAT/HSET
 * и Lua-скрипта минимума и максимума длительности:
 * параллельные воркеры не теряют инкременты, счётчики переживают рестарты воркеров.
 *
 * Нечистый рестарт Redis (падение, восстановление из RDB, переключение на отстающую реплику)
 * откатывает счётчики назад, и increase() за длинный период посчитал бы их весь накопленный
 * итог. Поэтому при смене run_id Redis счётчики обнуляются (resetCountersAfterRedisRestart):
 * Prometheus видит обычный сброс, теряются только попытки между рестартом и первым сбором.
 *
 * Гистограммы хранятся некумулятивно: замер увеличивает только наименьший подходящий
 * bucket. Кумулятивные значения считаются при чтении, поэтому смена bucket-ов в конфиге
 * не портит накопленные данные.
 *
 * Поле хеша — JSON-массив значений меток. Данные не устаревают: серии удалённых очередей
 * и классов уходят только вместе с ключами prometheus:queue:* (их можно удалить, rate()
 * и increase() переживают сброс счётчиков). Поля с другим числом меток (например, после
 * смены их набора) при чтении пропускаются, а не ломают весь /metrics.
 */
class QueueJobMetricsStorage
{
    private const JOBS_TOTAL_KEY = 'prometheus:queue:jobs_total';
    private const DURATION_HISTOGRAM = 'prometheus:queue:job_duration_seconds';
    private const WAIT_HISTOGRAM = 'prometheus:queue:job_wait_seconds';
    private const READY_AT_KEY = 'prometheus:queue:ready_at:';
    private const DURATION_EXTREMES_KEY = 'prometheus:queue:job_duration_extremes:';
    private const META_KEY = 'prometheus:queue:meta';
    private const BATCH_JOB_CLASSES_KEY = 'prometheus:queue:batch_job_classes';
    private const INF_LABEL = '+Inf';

    // Только счётчики
    private const COUNTER_KEYS = [
        self::JOBS_TOTAL_KEY,
        self::DURATION_HISTOGRAM . ':buckets',
        self::DURATION_HISTOGRAM . ':sum',
        self::WAIT_HISTOGRAM . ':buckets',
        self::WAIT_HISTOGRAM . ':sum',
    ];

    // Число меток в поле хеша: connection, queue и ещё job_class у метрик джоба
    private const QUEUE_LABEL_COUNT = 2;
    private const JOB_LABEL_COUNT = 3;

    // Отложенный джоб может пролежать в очереди дольше задержки, если её воркеры заняты
    private const READY_AT_EXTRA_TTL = 86400;

    // Связь вида батча с очередью и классом забывается, если её не видели неделю
    private const BATCH_JOB_CLASSES_TTL = 604800;

    // Окно минимума и максимума длительности, секунды. Сбор читает текущее и прошлое окно
    private const EXTREMES_WINDOW = 60;

    // KEYS: хеши минимума и максимума окна. ARGV: поле, длительность, TTL ключей.
    // Скрипт, а не ZADD GT/LT: те есть только с Redis 6.2
    private const EXTREMES_SCRIPT = <<<'LUA'
        local value = tonumber(ARGV[2])
        local min = tonumber(redis.call('hget', KEYS[1], ARGV[1]))
        if not min or value < min then
            redis.call('hset', KEYS[1], ARGV[1], ARGV[2])
        end
        local max = tonumber(redis.call('hget', KEYS[2], ARGV[1]))
        if not max or value > max then
            redis.call('hset', KEYS[2], ARGV[1], ARGV[2])
        end
        redis.call('expire', KEYS[1], ARGV[3])
        redis.call('expire', KEYS[2], ARGV[3])
        return 0
        LUA;

    // KEYS: meta, затем ключи счётчиков. ARGV: текущий run_id.
    // Сравнение и удаление в одном скрипте: параллельные сборы не удалят счётчики дважды
    private const RESET_SCRIPT = <<<'LUA'
        if redis.call('hget', KEYS[1], 'run_id') == ARGV[1] then
            return 0
        end
        redis.call('del', unpack(KEYS, 2))
        redis.call('hset', KEYS[1], 'run_id', ARGV[1])
        return 1
        LUA;

    public function recordAttempt(
        string $connection,
        string $queue,
        string $job,
        JobAttemptStatus $status,
        ?float $durationSeconds,
        ?float $waitSeconds,
    ): void {
        $queueLabels = [$connection, $queue];
        $jobLabels = [...$queueLabels, $job];
        $bounds = $this->bounds();

        $this->redis()->pipeline(function (object $pipe) use (
            $queueLabels,
            $jobLabels,
            $status,
            $durationSeconds,
            $waitSeconds,
            $bounds,
        ): void {
            $pipe->hincrby(self::JOBS_TOTAL_KEY, $this->encode([...$jobLabels, $status->value]), 1);

            if ($durationSeconds !== null) {
                $this->observe($pipe, self::DURATION_HISTOGRAM, $jobLabels, $durationSeconds, $bounds);

                $window = $this->extremesWindow(time());
                $pipe->eval(self::EXTREMES_SCRIPT, [
                    $window . ':min',
                    $window . ':max',
                    $this->encode($jobLabels),
                    $durationSeconds,
                    self::EXTREMES_WINDOW * 3,
                ], 2);
            }

            if ($waitSeconds !== null) {
                $this->observe($pipe, self::WAIT_HISTOGRAM, $queueLabels, $waitSeconds, $bounds);
            }
        });
    }

    /**
     * Запоминает, когда отложенный при отправке джоб станет доступен воркерам:
     * Horizon не пишет delay в payload, и без этого ожидание включало бы задержку.
     */
    public function rememberReadyAt(string $jobId, int $delaySeconds): void
    {
        $this->redis()->set(
            self::READY_AT_KEY . $jobId,
            time() + $delaySeconds,
            'EX',
            $delaySeconds + self::READY_AT_EXTRA_TTL,
        );
    }

    /**
     * Запоминает, что в батчах вида $kind есть джобы класса $job в очереди $queue соединения $connection:
     * по этой связи фильтр батча на дашборде сужает очереди и задачи, а заголовок таблицы батчей вида
     * показывает его очереди и соединения. Оценка — время, когда связь видели последний раз.
     */
    public function rememberBatchJobClass(string $kind, string $connection, string $queue, string $job): void
    {
        $this->redis()->zadd(self::BATCH_JOB_CLASSES_KEY, time(), $this->encode([$kind, $connection, $queue, $job]));
    }

    /**
     * Связи из rememberBatchJobClass за последнюю неделю; старые удаляются при чтении.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}> вид батча, соединение, очередь, класс
     */
    public function batchJobClasses(): array
    {
        $forgottenBefore = (string) (time() - self::BATCH_JOB_CLASSES_TTL);
        $this->redis()->zremrangebyscore(self::BATCH_JOB_CLASSES_KEY, '-inf', $forgottenBefore);
        $result = [];

        foreach ((array) $this->redis()->zrange(self::BATCH_JOB_CLASSES_KEY, 0, -1) as $member) {
            $link = $this->decode((string) $member, 4);

            if ($link !== null) {
                $result[] = $link;
            }
        }

        return $result;
    }

    /**
     * Забирает и удаляет время из rememberReadyAt одним обращением к Redis.
     * GET + DEL пайплайном, а не GETDEL: тот есть только с Redis 6.2.
     */
    public function pullReadyAt(string $jobId): ?int
    {
        $key = self::READY_AT_KEY . $jobId;

        [$readyAt] = $this->redis()->pipeline(function (object $pipe) use ($key): void {
            $pipe->get($key);
            $pipe->del($key);
        });

        return is_numeric($readyAt) ? (int) $readyAt : null;
    }

    /**
     * Обнуляет счётчики, если с прошлого сбора сменился процесс Redis (run_id). Если run_id
     * не получить, ничего не удаляет: при ошибке счётчики не трогаем.
     *
     * @return bool true — счётчики удалены
     */
    public function resetCountersAfterRedisRestart(): bool
    {
        $runId = $this->redisRunId();

        if ($runId === null) {
            return false;
        }

        // phpredis на ошибку Lua возвращает false, а не исключение: это тоже «не удаляли»
        return $this->redis()->eval(
            self::RESET_SCRIPT,
            1 + count(self::COUNTER_KEYS),
            self::META_KEY,
            ...[...self::COUNTER_KEYS, $runId],
        ) === 1;
    }

    /**
     * Поле счётчика создаётся первым HINCRBY, и Prometheus впервые видит серию уже со значением 1:
     * этот шаг increase() и rate() не считают. Поэтому для каждого класса, который уже выполнялся
     * в очереди, отдаются все статусы, недостающие — нулями: первый таймаут или провал известного
     * класса виден. Самая первая попытка нового класса в очереди всё равно не видна.
     *
     * @return list<array{0: float, 1: list<string>}> метки: connection, queue, job_class, status
     */
    public function jobsTotal(): array
    {
        $samples = [];
        $jobs = [];

        foreach ($this->samples(self::JOBS_TOTAL_KEY, self::JOB_LABEL_COUNT + 1) as [$value, $labels]) {
            $job = array_slice($labels, 0, self::JOB_LABEL_COUNT);
            $samples[$this->encode($labels)] = [$value, $labels];
            $jobs[$this->encode($job)] = $job;
        }

        foreach ($jobs as $job) {
            foreach (JobAttemptStatus::cases() as $status) {
                $labels = [...$job, $status->value];
                $samples[$this->encode($labels)] ??= [0.0, $labels];
            }
        }

        return array_values($samples);
    }

    /**
     * Длительность попыток. Метки: connection, queue, job_class, у bucket-ов ещё le.
     *
     * @return array{buckets: list<array{0: int, 1: list<string>}>, sums: list<array{0: float, 1: list<string>}>,
     *     counts: list<array{0: int, 1: list<string>}>}
     */
    public function durationHistogram(): array
    {
        return $this->histogram(self::DURATION_HISTOGRAM, self::JOB_LABEL_COUNT);
    }

    /**
     * Самая короткая и самая долгая попытка класса за текущее и прошлое окно. Сбор не реже
     * раза в минуту видит каждую попытку, и min_over_time/max_over_time в Prometheus дают
     * точные значения за период, а не границы bucket-ов гистограммы.
     *
     * @return array{min: list<array{0: float, 1: list<string>}>, max: list<array{0: float, 1: list<string>}>}
     *     метки: connection, queue, job_class
     */
    public function durationExtremes(): array
    {
        $windows = [$this->extremesWindow(time() - self::EXTREMES_WINDOW), $this->extremesWindow(time())];
        $result = [];

        foreach (['min' => min(...), 'max' => max(...)] as $kind => $pick) {
            $samples = [];

            foreach ($windows as $window) {
                foreach ($this->samples($window . ':' . $kind, self::JOB_LABEL_COUNT) as [$value, $labels]) {
                    $id = $this->encode($labels);
                    $samples[$id] = [isset($samples[$id]) ? $pick($samples[$id][0], $value) : $value, $labels];
                }
            }

            $result[$kind] = array_values($samples);
        }

        return $result;
    }

    /**
     * Ожидание первой попытки в очереди. Метки: connection, queue, у bucket-ов ещё le.
     *
     * @return array{buckets: list<array{0: int, 1: list<string>}>, sums: list<array{0: float, 1: list<string>}>,
     *     counts: list<array{0: int, 1: list<string>}>}
     */
    public function waitHistogram(): array
    {
        return $this->histogram(self::WAIT_HISTOGRAM, self::QUEUE_LABEL_COUNT);
    }

    /**
     * @param list<string> $labels
     * @param list<float> $bounds
     */
    private function observe(object $pipe, string $histogram, array $labels, float $value, array $bounds): void
    {
        $pipe->hincrby($histogram . ':buckets', $this->encode([...$labels, $this->bucketFor($value, $bounds)]), 1);
        $pipe->hincrbyfloat($histogram . ':sum', $this->encode($labels), $value);
    }

    /**
     * Кумулятивные bucket-ы с +Inf, суммы и количества. Количество берётся из bucket-а +Inf,
     * поэтому _count всегда сходится с _bucket.
     *
     * @return array{buckets: list<array{0: int, 1: list<string>}>, sums: list<array{0: float, 1: list<string>}>,
     *     counts: list<array{0: int, 1: list<string>}>}
     */
    private function histogram(string $histogram, int $labelCount): array
    {
        $bounds = [...$this->bounds(), INF];
        $buckets = [];
        $counts = [];

        foreach ($this->observations($histogram . ':buckets', $labelCount) as $series) {
            ['labels' => $labels, 'observed' => $observed] = $series;
            $cumulative = 0;
            $next = 0;

            foreach ($bounds as $bound) {
                while (isset($observed[$next]) && $observed[$next][0] <= $bound) {
                    $cumulative += $observed[$next++][1];
                }

                $buckets[] = [$cumulative, [...$labels, $this->boundLabel($bound)]];
            }

            $counts[] = [$cumulative, $labels];
        }

        return [
            'buckets' => $buckets,
            'sums' => $this->samples($histogram . ':sum', $labelCount),
            'counts' => $counts,
        ];
    }

    /**
     * Некумулятивные счётчики bucket-ов по наборам меток, по возрастанию границы.
     *
     * @param int $labelCount число меток без le
     * @return list<array{labels: list<string>, observed: list<array{0: float, 1: int}>}>
     */
    private function observations(string $key, int $labelCount): array
    {
        $series = [];

        foreach ($this->redis()->hgetall($key) as $field => $count) {
            $labels = $this->decode((string) $field, $labelCount + 1);
            $le = $labels === null ? null : array_pop($labels);

            if ($le === null || ($le !== self::INF_LABEL && !is_numeric($le))) {
                continue;
            }

            $id = $this->encode($labels);
            $series[$id]['labels'] = $labels;
            $series[$id]['observed'][] = [$le === self::INF_LABEL ? INF : (float) $le, (int) $count];
        }

        return array_values(array_map(function (array $item): array {
            usort($item['observed'], fn (array $a, array $b): int => $a[0] <=> $b[0]);

            return $item;
        }, $series));
    }

    /**
     * @return list<array{0: float, 1: list<string>}>
     */
    private function samples(string $key, int $labelCount): array
    {
        $result = [];

        foreach ($this->redis()->hgetall($key) as $field => $value) {
            $labels = $this->decode((string) $field, $labelCount);

            if ($labels !== null) {
                $result[] = [(float) $value, $labels];
            }
        }

        return $result;
    }

    /**
     * @param list<float> $bounds
     */
    private function bucketFor(float $value, array $bounds): string
    {
        foreach ($bounds as $bound) {
            if ($value <= $bound) {
                return $this->boundLabel($bound);
            }
        }

        return self::INF_LABEL;
    }

    private function boundLabel(float $bound): string
    {
        return $bound === INF ? self::INF_LABEL : (string) $bound;
    }

    /**
     * @return list<float>
     */
    private function bounds(): array
    {
        $bounds = array_map(floatval(...), config('horizon-prometheus-grafana.duration_buckets', []));
        sort($bounds);

        return $bounds;
    }

    private function extremesWindow(int $time): string
    {
        return self::DURATION_EXTREMES_KEY . intdiv($time, self::EXTREMES_WINDOW);
    }

    private function redisRunId(): ?string
    {
        try {
            $info = $this->redis()->info('server');
        } catch (Throwable) {
            return null;
        }

        // phpredis отдаёт плоский массив, predis — с разбивкой по секциям
        $runId = $info['run_id'] ?? $info['Server']['run_id'] ?? null;

        return is_string($runId) && $runId !== '' ? $runId : null;
    }

    private function encode(array $values): string
    {
        return json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Значения меток из поля хеша или null, если поле другого формата: promphp бросает
     * исключение на неверное число меток, и одно такое поле уронило бы весь /metrics.
     *
     * @return list<string>|null
     */
    private function decode(string $field, int $labelCount): ?array
    {
        $labels = json_decode($field, true);

        if (!is_array($labels) || !array_is_list($labels) || count($labels) !== $labelCount) {
            return null;
        }

        foreach ($labels as $label) {
            if (!is_string($label)) {
                return null;
            }
        }

        return $labels;
    }

    private function redis(): Connection
    {
        return Redis::connection(config('horizon-prometheus-grafana.redis_connection'));
    }
}
