<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Prometheus;

use Closure;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;
use SushiMarket\HorizonPrometheusGrafana\QueueState;
use SushiMarket\HorizonPrometheusGrafana\RedisQueueStateReader;

/**
 * Состояние Redis-очередей Horizon: один Lua-скрипт на очередь за сбор (RedisQueueStateReader).
 *
 * Метрики по очереди публикуются для каждой очереди из config/horizon.php, в том числе нулевые:
 * пустая очередь видна как 0, а не как отсутствие данных. Разбивка по классам есть только
 * у классов, джобы которых сейчас в очереди.
 */
class QueueStateCollector implements Collector
{
    private const QUEUE_LABELS = ['connection', 'queue'];
    private const CLASS_LABELS = ['connection', 'queue', 'job_class'];

    public function register(): void
    {
        Prometheus::addGauge('Queue pending jobs')
            ->name('queue_pending_jobs')
            ->helpText('Джобы, готовые к выполнению и ждущие воркера, включая отложенные с наступившим сроком')
            ->labels(self::QUEUE_LABELS)
            ->value(fn (): array => $this->perQueue(
                fn (QueueState $state): int => $state->pending + $state->delayedDue,
            ));

        Prometheus::addGauge('Queue pending jobs by class')
            ->name('queue_jobs_pending')
            ->helpText('Джобы, готовые к выполнению и ждущие воркера. За лимитом просмотра — «(не просмотрено)»')
            ->labels(self::CLASS_LABELS)
            ->value(fn (): array => $this->perClass(fn (QueueState $state): array => $state->pendingByClass));

        Prometheus::addGauge('Queue reserved jobs by class')
            ->name('queue_jobs_reserved')
            ->helpText('Джобы, взятые воркерами: выполняются сейчас или брошены убитым воркером до retry_after')
            ->labels(self::CLASS_LABELS)
            ->value(fn (): array => $this->perClass(fn (QueueState $state): array => $state->reservedByClass));

        Prometheus::addGauge('Queue delayed jobs')
            ->name('queue_delayed_jobs')
            ->helpText('Отложенные джобы: delay при отправке или release() с задержкой, '
                . 'в том числе с наступившим сроком (office_queue_delayed_due_jobs)')
            ->labels(self::QUEUE_LABELS)
            ->value(fn (): array => $this->perQueue(fn (QueueState $state): int => $state->delayed));

        Prometheus::addGauge('Queue delayed due jobs')
            ->name('queue_delayed_due_jobs')
            ->helpText('Отложенные джобы, срок которых наступил, но ни один воркер очереди ещё не забрал их')
            ->labels(self::QUEUE_LABELS)
            ->value(fn (): array => $this->perQueue(fn (QueueState $state): int => $state->delayedDue));

        Prometheus::addGauge('Queue oldest pending job age')
            ->name('queue_oldest_pending_job_age')
            ->helpText('Сколько секунд ждёт самый старый готовый джоб: головной — от постановки в очередь '
                . '(с задержкой при отправке), отложенный с наступившим сроком — от срока; 0 — таких нет')
            ->labels(self::QUEUE_LABELS)
            ->value(fn (): array => $this->perQueue(
                fn (QueueState $state): ?int => $state->pending === 0 && $state->delayedDue === 0
                    ? 0
                    : $this->age($state->oldestReadyAt),
            ));
    }

    /**
     * @param Closure(QueueState): (int|null) $value null — значение неизвестно, серия не публикуется
     * @return list<array{0: int, 1: list<string>}>
     */
    private function perQueue(Closure $value): array
    {
        $result = [];

        foreach ($this->states() as $state) {
            $sample = $value($state);

            if ($sample !== null) {
                $result[] = [$sample, [$state->connection, $state->queue]];
            }
        }

        return $result;
    }

    /**
     * @param Closure(QueueState): array<string, int|null> $values null — значение неизвестно
     * @return list<array{0: int, 1: list<string>}>
     */
    private function perClass(Closure $values): array
    {
        $result = [];

        foreach ($this->states() as $state) {
            foreach ($values($state) as $class => $sample) {
                if ($sample !== null) {
                    $result[] = [$sample, [$state->connection, $state->queue, (string) $class]];
                }
            }
        }

        return $result;
    }

    /**
     * @return list<QueueState>
     */
    private function states(): array
    {
        return app(ScrapeCache::class)->remember(
            self::class,
            fn (): array => app(RedisQueueStateReader::class)->read(),
        );
    }

    private function age(int|float|null $since): ?int
    {
        return $since === null ? null : max(0, (int) (time() - $since));
    }
}
