<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Prometheus;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;
use SushiMarket\HorizonPrometheusGrafana\BatchKind;
use SushiMarket\HorizonPrometheusGrafana\QueueJobMetricsStorage;

/**
 * Батчи (Bus::batch) из таблицы queue.batching: выполняющиеся сейчас по видам и отдельные батчи —
 * из них дашборд считает итоги за выбранный период по истории в Prometheus.
 *
 * Метка batch — вид батча (BatchKind), name — имя батча (без имени — id). Отдельный батч отдаётся, пока выполняется
 * и сутки после закрытия (старше суток их удаляет queue:prune-batches), не больше RECENT_PER_KIND
 * последних на вид. Батч с прошлых суток дашборд берёт из истории Prometheus.
 *
 * Батч выполняется, пока у него есть невыполненные джобы, кроме упавших. Упавший джоб не уменьшает
 * pending_jobs, поэтому батч с allowFailures и упавшими джобами не получает finished_at никогда,
 * хотя всё выполнил (Laravel в этот момент вызывает finally) — такой считается закрытым с ошибками,
 * а временем закрытия — время создания. queue:prune-batches без --unfinished такие не удаляет.
 * Строку батча Laravel создаёт с total_jobs = 0 и только потом добавляет джобы: такой ещё выполняется,
 * пустой батч Laravel закрывает сразу, с finished_at.
 */
class QueueBatchesCollector implements Collector
{
    private const CLOSED_WINDOW_SECONDS = 86400;
    private const RECENT_PER_KIND = 200;

    public function register(): void
    {
        Prometheus::addGauge('Queue batches running')
            ->name('queue_batches_running')
            ->helpText('Батчи, которые выполняются: не отменены и есть невыполненные джобы, кроме упавших')
            ->label('batch')
            ->value(fn (): array => $this->perKind(fn (array $kind): array => [$kind['running']]));

        Prometheus::addGauge('Queue batch jobs')
            ->name('queue_batch_jobs')
            ->helpText('Джобы выполняющихся батчей: total — всего, pending — ещё не выполнены, failed — упали')
            ->labels(['batch', 'state'])
            ->value(fn (): array => $this->perKind(fn (array $kind): array => [
                [$kind['total'], 'total'],
                [$kind['pending'], 'pending'],
                [$kind['failed'], 'failed'],
            ]));

        Prometheus::addGauge('Queue batch oldest age')
            ->name('queue_batch_oldest_age_seconds')
            ->helpText('Сколько секунд выполняется самый старый из выполняющихся батчей')
            ->label('batch')
            ->value(fn (): array => $this->perKind(
                fn (array $kind): array => $kind['oldest'] === null ? [] : [time() - $kind['oldest']],
            ));

        Prometheus::addGauge('Queue batch job counts')
            ->name('queue_batch_job_counts')
            ->helpText('Джобы отдельного батча: total — всего, pending — не выполнены (с упавшими, как у Laravel), '
                . 'failed — упали. Обработано, как у Horizon и Laravel, — total минус pending')
            ->labels(['batch', 'name', 'state'])
            ->value(fn (): array => $this->perBatch(fn (array $batch): array => [
                [$batch['total'], 'total'],
                [$batch['pending'], 'pending'],
                [$batch['failed'], 'failed'],
            ]));

        Prometheus::addGauge('Queue batch created timestamp')
            ->name('queue_batch_created_timestamp_seconds')
            ->helpText('Когда создан батч, unix-время')
            ->labels(['batch', 'name'])
            ->value(fn (): array => $this->perBatch(fn (array $batch): array => [$batch['created']]));

        Prometheus::addGauge('Queue batch closed timestamp')
            ->name('queue_batch_closed_timestamp_seconds')
            ->helpText('Когда батч закрыт (завершён или отменён), unix-время; у выполняющегося ряда нет')
            ->labels(['batch', 'name'])
            ->value(fn (): array => $this->perBatch(
                fn (array $batch): array => $batch['closed'] === null ? [] : [$batch['closed']],
            ));

        Prometheus::addGauge('Queue batch job classes')
            ->name('queue_batch_job_classes')
            ->helpText('Из каких классов джобов и очередей состоят батчи вида: 1 на сочетание, '
                . 'виденное при отправке за последнюю неделю')
            ->labels(['batch', 'queue', 'job_class'])
            ->value(fn (): array => $this->jobClasses());

        Prometheus::addGauge('Queue batch info')
            ->name('queue_batch_info')
            ->helpText('Очереди и соединения джобов батчей вида через запятую, за последнюю неделю: 1 на вид')
            ->labels(['batch', 'queue', 'connection'])
            ->value(fn (): array => $this->info());

        Prometheus::addGauge('Queue batch progress')
            ->name('queue_batch_progress')
            ->helpText('Процент обработанных джобов батча, как у Horizon. Метки — для списка на дашборде: '
                . 'status — Running, Finished, Failures или Cancelled, id — для ссылки на батч в Horizon')
            ->labels(['batch', 'name', 'status', 'size', 'created', 'id'])
            ->value(fn (): array => $this->perBatch(fn (array $batch): array => [[
                $batch['progress'], $batch['status'], $batch['total'], $batch['created_label'], $batch['id'],
            ]]));
    }

    /**
     * @return list<array{0: int, 1: list<string>}>
     */
    private function jobClasses(): array
    {
        $result = [];

        // Соединение в метки не входит: одинаковые очередь и класс в разных соединениях — один ряд
        foreach ($this->links() as [$kind, , $queue, $job]) {
            $result["{$kind}\0{$queue}\0{$job}"] = [1, [$kind, $queue, $job]];
        }

        return array_values($result);
    }

    /**
     * @return list<array{0: int, 1: list<string>}>
     */
    private function info(): array
    {
        $kinds = [];

        foreach ($this->links() as [$kind, $connection, $queue]) {
            $kinds[$kind]['queues'][$queue] = true;
            $kinds[$kind]['connections'][$connection] = true;
        }

        $result = [];

        foreach ($kinds as $kind => $values) {
            $result[] = [1, [$kind, $this->joined($values['queues']), $this->joined($values['connections'])]];
        }

        return $result;
    }

    /**
     * @param array<string, true> $values
     */
    private function joined(array $values): string
    {
        $values = array_map('strval', array_keys($values));
        sort($values);

        return implode(', ', $values);
    }

    /**
     * Связи «вид батча, соединение, очередь, класс» из QueueJobMetricsStorage — одно чтение за сбор.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function links(): array
    {
        return app(ScrapeCache::class)->remember(
            self::class . ':links',
            fn (): array => app(QueueJobMetricsStorage::class)->batchJobClasses(),
        );
    }

    /**
     * Ряды метрики по видам: $values отдаёт список [значение, ...метки после batch] или одно значение.
     *
     * @param callable(array<string, int|null>): list<int|list<int|string>> $values
     * @return list<array{0: int, 1: list<string>}>
     */
    private function perKind(callable $values): array
    {
        $result = [];

        foreach ($this->stats()['kinds'] as $kind => $stats) {
            foreach ($values($stats) as $value) {
                $result[] = $this->row([$kind], $value);
            }
        }

        return $result;
    }

    /**
     * Ряды метрики по отдельным батчам: $values отдаёт список [значение, ...метки после batch и name]
     * или одно значение.
     *
     * @param callable(array<string, int|string|null>): list<int|list<int|string>> $values
     * @return list<array{0: int, 1: list<string>}>
     */
    private function perBatch(callable $values): array
    {
        $result = [];

        foreach ($this->stats()['batches'] as $batch) {
            foreach ($values($batch) as $value) {
                $result[] = $this->row([$batch['kind'], $batch['name']], $value);
            }
        }

        return $result;
    }

    /**
     * @param list<string> $labels
     * @param int|list<int|string> $value
     * @return array{0: int, 1: list<string>}
     */
    private function row(array $labels, int|array $value): array
    {
        if (!is_array($value)) {
            return [$value, $labels];
        }

        return [(int) $value[0], [...$labels, ...array_map('strval', array_slice($value, 1))]];
    }

    /**
     * Выполняющиеся по видам и отдельные батчи: выполняющиеся и закрытые за сутки.
     *
     * @return array{kinds: array<string, array<string, int|null>>, batches: list<array<string, int|string|null>>}
     */
    private function stats(): array
    {
        return app(ScrapeCache::class)->remember(self::class, function (): array {
            $since = time() - self::CLOSED_WINDOW_SECONDS;
            $kinds = [];
            $batches = [];

            $rows = DB::connection(config('queue.batching.database'))
                ->table(config('queue.batching.table'))
                ->select([
                    'id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'created_at', 'finished_at',
                    'cancelled_at',
                ])
                ->where(fn ($query) => $query->whereNull('finished_at')->whereNull('cancelled_at'))
                ->orWhere('finished_at', '>=', $since)
                ->orWhere('cancelled_at', '>=', $since)
                ->get();

            foreach ($rows as $row) {
                $batch = $this->batch($row);

                // Выполнивший всё, но без finished_at, закрыт временем создания: старше суток не отдаётся
                if ($batch['closed'] !== null && $batch['closed'] < $since) {
                    continue;
                }

                $batches[$batch['kind']][] = $batch;
                $kinds[$batch['kind']] ??= [
                    'running' => 0, 'total' => 0, 'pending' => 0, 'failed' => 0, 'oldest' => null,
                ];

                if ($batch['status'] === 'Running') {
                    $kinds[$batch['kind']] = [
                        'running' => $kinds[$batch['kind']]['running'] + 1,
                        'total' => $kinds[$batch['kind']]['total'] + $batch['total'],
                        'pending' => $kinds[$batch['kind']]['pending'] + $batch['pending'],
                        'failed' => $kinds[$batch['kind']]['failed'] + $batch['failed'],
                        'oldest' => min($kinds[$batch['kind']]['oldest'] ?? PHP_INT_MAX, $batch['created']),
                    ];
                }
            }

            return ['kinds' => $kinds, 'batches' => $this->latest($batches)];
        });
    }

    /**
     * @return array<string, int|string|null>
     */
    private function batch(object $row): array
    {
        $total = (int) $row->total_jobs;
        $pending = (int) $row->pending_jobs;
        $failed = (int) $row->failed_jobs;
        $created = (int) $row->created_at;
        $status = match (true) {
            $row->cancelled_at !== null => 'Cancelled',
            $row->finished_at !== null || ($total > 0 && $pending === $failed) => $failed > 0 ? 'Failures' : 'Finished',
            default => 'Running',
        };

        return [
            'id' => (string) $row->id,
            'kind' => BatchKind::of($row->name),
            'name' => (string) ($row->name ?: $row->id),
            'status' => $status,
            'total' => $total,
            'pending' => $pending,
            'failed' => $failed,
            'progress' => $total > 0 ? (int) round(($total - $pending) / $total * 100) : 0,
            'created' => $created,
            'created_label' => CarbonImmutable::createFromTimestamp($created, config('app.timezone'))
                ->format('Y-m-d H:i:s T'),
            'closed' => $status === 'Running' ? null : (int) ($row->cancelled_at ?? $row->finished_at ?? $created),
        ];
    }

    /**
     * Последние RECENT_PER_KIND батчей каждого вида.
     *
     * @param array<string, list<array<string, int|string|null>>> $batches
     * @return list<array<string, int|string|null>>
     */
    private function latest(array $batches): array
    {
        $result = [];

        foreach ($batches as $kind) {
            usort($kind, fn (array $a, array $b): int => $b['created'] <=> $a['created']);
            array_push($result, ...array_slice($kind, 0, self::RECENT_PER_KIND));
        }

        return $result;
    }
}
