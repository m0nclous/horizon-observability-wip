<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Временный: нагрузка для проверки дашборда «Queues», ставится командой queue-metrics:load-test.
 * На удаление вместе с командой, см. docs/cleanup.md.
 *
 * Под одним классом идут разные тестовые задачи: метрики и Horizon берут имя джоба
 * из displayName, поэтому каждая задача — отдельная строка на дашборде.
 *
 * Провал идёт через fail(), а не исключением: попытка считается failed, а исключение
 * не уходит в обработчик и в Sentry. Повтор — исключение с ShouldntReport на первой попытке,
 * вторая выполняется. Исчерпание — такое исключение на каждой из EXHAUSTED_TRIES попыток: две
 * повторяются, на последней Laravel проваливает джоб, и в Horizon у упавшего Attempts = 3.
 * Таймаут у всех джобов задачи один, как у настоящего класса: вдвое больше средней длительности,
 * но не меньше 5 с. Таймаут убивает воркер, Horizon поднимает его заново.
 *
 * В батче таймаут задаётся явно: учёт батча (строка в job_batches с блокировкой) входит в таймаут
 * джоба, и с медленной БД воркеры одного батча ждут друг друга дольше самой работы. Джоб отменённого
 * батча сразу выходит, как принято в настоящих батчах.
 */
class QueueLoadTestJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public const OUTCOME_COMPLETED = 'completed';
    public const OUTCOME_RETRIED = 'retried';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_TIMED_OUT = 'timed_out';
    public const OUTCOME_EXHAUSTED = 'exhausted';

    private const EXHAUSTED_TRIES = 3;

    // Провал пишет в failed_jobs, и у быстрых задач с таймаутом в 1–2 с запись в медленную БД не укладывалась бы
    private const MIN_TIMEOUT_SECONDS = 5;

    public int $tries = 1;

    public ?int $timeout = null;

    public function __construct(
        public string $name,
        public string $outcome,
        public float $durationSeconds,
        ?int $timeoutSeconds = null,
    ) {
        $this->timeout = $timeoutSeconds ?? max(self::MIN_TIMEOUT_SECONDS, (int) ceil($durationSeconds * 2));

        $this->tries = match ($outcome) {
            self::OUTCOME_RETRIED => 2,
            self::OUTCOME_EXHAUSTED => self::EXHAUSTED_TRIES,
            default => 1,
        };
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->outcome === self::OUTCOME_TIMED_OUT) {
            $this->sleepFor($this->timeout + 2);

            return;
        }

        // Разброс от половины до полутора средних, чтобы среднее и p95 на дашборде различались
        $this->sleepFor($this->durationSeconds * random_int(500, 1500) / 1000);

        if ($this->outcome === self::OUTCOME_FAILED) {
            $this->fail(new RuntimeException('Тестовый провал: нагрузка queue-metrics:load-test'));
        }

        if (
            $this->outcome === self::OUTCOME_EXHAUSTED
            || ($this->outcome === self::OUTCOME_RETRIED && $this->attempts() === 1)
        ) {
            throw new class ('Тестовое исключение: нагрузка queue-metrics:load-test')
                extends RuntimeException
                implements ShouldntReport {
            };
        }
    }

    /**
     * usleep прерывается сигналом: уменьшая число процессов, Horizon шлёт воркеру SIGTERM,
     * и джоб закончился бы раньше срока. Поэтому досыпаем до него.
     */
    private function sleepFor(float $seconds): void
    {
        $until = hrtime(true) + (int) round($seconds * 1e9);

        while (($left = $until - hrtime(true)) > 0) {
            usleep(min(intdiv($left, 1000), 1_000_000));
        }
    }
}
