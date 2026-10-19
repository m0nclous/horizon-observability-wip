<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Batches\BatchLink;
use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Batches\BatchReader;
use DF\HorizonObservability\Batches\BatchSnapshot;
use DF\HorizonObservability\Batches\BatchStatus;
use DF\HorizonObservability\Metrics\Labels;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;
use RuntimeException;
use Throwable;

/**
 * Collector `batches`: queue_batch*.
 *
 * Two sources, each a partial failure of its own ({@see ScrapeContext::reportFailure()}: the data of the other source
 * is kept, `exporter_collector_success` is 0):
 * - the batch table ({@see BatchReader::read()}): running batches of the last 7 days and batches closed during the
 *   last hour;
 * - the batch links ({@see BatchLinks}): queues and job classes of the kinds during the last 7 days, pruned once per
 *   scrape.
 *
 * Families:
 * - per kind: `queue_batches_running`, `queue_batch_jobs` (sums over running batches; every kind that has a batch in
 *   the read, with 0 when none is running) and `queue_batch_oldest_age_seconds` (kinds with a running batch);
 * - per kind from the links: `queue_batch_job_classes` (connection is not a label) and `queue_batch_info` (queues and
 *   connections joined with ", ", sorted);
 * - per batch, labelled by kind and name (the id when unnamed): `queue_batch_job_counts`,
 *   `queue_batch_created_timestamp_seconds`, `queue_batch_closed_timestamp_seconds` (closed batches only) and
 *   `queue_batch_progress` (with `status` and `id`). Kind and name identify a batch on the dashboard, so of several
 *   batches with the same kind and name only the newest is exported.
 *
 * Cardinality caps: at most {@see self::MAX_KINDS} kinds, the most recently active first (latest creation,
 * closing or link time); the other kinds are folded into `(other)` in the per-kind families. Per-batch families cover
 * at most {@see self::MAX_BATCHES_PER_KIND} batches per kind and {@see self::MAX_BATCHES} per scrape, newest first,
 * and only kinds that are not folded.
 *
 * @internal
 */
final readonly class BatchesCollector implements Collector
{
    public const MAX_KINDS = 100;

    public const MAX_BATCHES_PER_KIND = 200;

    public const MAX_BATCHES = 2000;

    private const STATES = ['total', 'pending', 'failed'];

    public function __construct(private BatchReader $reader, private BatchLinks $links) {}

    public function name(): string
    {
        return 'batches';
    }

    public function collect(ScrapeContext $context): iterable
    {
        $now = $context->now();
        $batches = [];
        $links = [];

        try {
            $batches = $this->reader->read($now);
        } catch (Throwable $e) {
            $context->reportFailure('database', $e);
        }

        if ($context->deadlineExceeded()) {
            $context->reportFailure('links', new RuntimeException('the scrape budget ran out before the batch links were read.'));
        } else {
            try {
                $links = $this->links->recent($now);
                // After the read, which ignores old links anyway: an unreachable storage costs one timeout, not two.
                $this->prune($context);
            } catch (Throwable $e) {
                $context->reportFailure('links', $e);
            }
        }

        return $this->families($batches, $links, $now->getTimestamp());
    }

    private function prune(ScrapeContext $context): void
    {
        try {
            $this->links->prune($context->now());
        } catch (Throwable $e) {
            $context->reportFailure('links.prune', $e);
        }
    }

    /**
     * @param  list<BatchSnapshot>  $batches  newest first
     * @param  list<array{link: BatchLink, seenAt: int}>  $links
     * @return list<MetricFamily>
     */
    private function families(array $batches, array $links, int $now): array
    {
        $labels = $this->kindLabels($batches, $links);
        [$running, $jobs, $oldest] = $this->perKind($batches, $labels, $now);
        [$counts, $created, $closed, $progress] = $this->perBatch($batches, $labels);
        [$jobClasses, $info] = $this->fromLinks($links, $labels);

        // Catalog order (Metric).
        return array_values(array_filter(
            [$running, $jobs, $oldest, $counts, $created, $closed, $jobClasses, $info, $progress],
            static fn (MetricFamily $family): bool => ! $family->isEmpty(),
        ));
    }

    /**
     * The `batch` label of every kind: the kind itself for the {@see self::MAX_KINDS} most recently active ones,
     * `(other)` for the rest.
     *
     * @param  list<BatchSnapshot>  $batches
     * @param  list<array{link: BatchLink, seenAt: int}>  $links
     * @return array<string, string> kind => label
     */
    private function kindLabels(array $batches, array $links): array
    {
        $activity = [];

        foreach ($batches as $batch) {
            $activity[$batch->kind] = max($activity[$batch->kind] ?? PHP_INT_MIN, $batch->createdAt, $batch->closedAt ?? PHP_INT_MIN);
        }

        foreach ($links as ['link' => $link, 'seenAt' => $seenAt]) {
            $activity[$link->kind] = max($activity[$link->kind] ?? PHP_INT_MIN, $seenAt);
        }

        $kinds = array_map(strval(...), array_keys($activity));
        usort($kinds, static fn (string $a, string $b): int => [$activity[$b], $a] <=> [$activity[$a], $b]);

        $labels = [];

        foreach ($kinds as $rank => $kind) {
            $labels[$kind] = $rank < self::MAX_KINDS ? $kind : Labels::OTHER;
        }

        return $labels;
    }

    /**
     * @param  list<BatchSnapshot>  $batches
     * @param  array<string, string>  $labels
     * @return array{0: MetricFamily, 1: MetricFamily, 2: MetricFamily}
     */
    private function perKind(array $batches, array $labels, int $now): array
    {
        /** @var array<string, array{running: int, total: int, pending: int, failed: int, oldest: int|null}> $kinds */
        $kinds = [];

        foreach ($batches as $batch) {
            $label = $labels[$batch->kind];
            $kinds[$label] ??= ['running' => 0, 'total' => 0, 'pending' => 0, 'failed' => 0, 'oldest' => null];

            if ($batch->status !== BatchStatus::Running) {
                continue;
            }

            $kinds[$label]['running']++;
            $kinds[$label]['total'] += $batch->total;
            $kinds[$label]['pending'] += $batch->pending;
            $kinds[$label]['failed'] += $batch->failed;
            $kinds[$label]['oldest'] = min($kinds[$label]['oldest'] ?? PHP_INT_MAX, $batch->createdAt);
        }

        $running = MetricFamily::of(Metric::BatchesRunning);
        $jobs = MetricFamily::of(Metric::BatchJobs);
        $oldest = MetricFamily::of(Metric::BatchOldestAge);

        foreach ($kinds as $label => $kind) {
            $label = (string) $label;
            $running->set(['batch' => $label], $kind['running']);

            foreach (self::STATES as $state) {
                $jobs->set(['batch' => $label, 'state' => $state], $kind[$state]);
            }

            if ($kind['oldest'] !== null) {
                $oldest->set(['batch' => $label], max(0, $now - $kind['oldest']));
            }
        }

        return [$running, $jobs, $oldest];
    }

    /**
     * @param  list<BatchSnapshot>  $batches  newest first
     * @param  array<string, string>  $labels
     * @return array{0: MetricFamily, 1: MetricFamily, 2: MetricFamily, 3: MetricFamily}
     */
    private function perBatch(array $batches, array $labels): array
    {
        $counts = MetricFamily::of(Metric::BatchJobCounts);
        $created = MetricFamily::of(Metric::BatchCreated);
        $closed = MetricFamily::of(Metric::BatchClosed);
        $progress = MetricFamily::of(Metric::BatchProgress);

        foreach ($this->exportedBatches($batches, $labels) as [$batch, $name]) {
            $series = ['batch' => $batch->kind, 'name' => $name];

            foreach (self::STATES as $state) {
                $counts->set([...$series, 'state' => $state], match ($state) {
                    'total' => $batch->total,
                    'pending' => $batch->pending,
                    'failed' => $batch->failed,
                });
            }

            $created->set($series, $batch->createdAt);

            if ($batch->closedAt !== null) {
                $closed->set($series, $batch->closedAt);
            }

            $progress->set([...$series, 'status' => $batch->status->value, 'id' => $batch->id], $batch->progress);
        }

        return [$counts, $created, $closed, $progress];
    }

    /**
     * The batches of the per-batch families with their `name` label: kinds that are not folded, the newest batch per
     * kind and name, at most {@see self::MAX_BATCHES_PER_KIND} per kind and {@see self::MAX_BATCHES} in all.
     *
     * @param  list<BatchSnapshot>  $batches  newest first
     * @param  array<string, string>  $labels
     * @return list<array{0: BatchSnapshot, 1: string}>
     */
    private function exportedBatches(array $batches, array $labels): array
    {
        $seen = [];
        $perKind = [];
        $exported = [];

        foreach ($batches as $batch) {
            if ($labels[$batch->kind] !== $batch->kind || ($perKind[$batch->kind] ?? 0) >= self::MAX_BATCHES_PER_KIND) {
                continue;
            }

            $name = Labels::sanitize($batch->name);
            $name = $name !== '' ? $name : Labels::sanitize($batch->id);
            $key = $batch->kind."\x1f".$name;

            if ($name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $perKind[$batch->kind] = ($perKind[$batch->kind] ?? 0) + 1;
            $exported[] = [$batch, $name];

            if (count($exported) >= self::MAX_BATCHES) {
                break;
            }
        }

        return $exported;
    }

    /**
     * @param  list<array{link: BatchLink, seenAt: int}>  $links
     * @param  array<string, string>  $labels
     * @return array{0: MetricFamily, 1: MetricFamily}
     */
    private function fromLinks(array $links, array $labels): array
    {
        $classes = [];
        $info = [];

        foreach ($links as ['link' => $link]) {
            $label = $labels[$link->kind];
            $classes[$label."\x1f".$link->queue."\x1f".$link->jobClass] = ['batch' => $label, 'queue' => $link->queue, 'job_class' => $link->jobClass];
            $info[$label]['queues'][$link->queue] = true;
            $info[$label]['connections'][$link->connection] = true;
        }

        $jobClasses = MetricFamily::of(Metric::BatchJobClasses);

        foreach ($classes as $series) {
            $jobClasses->set($series, 1);
        }

        $batchInfo = MetricFamily::of(Metric::BatchInfo);

        foreach ($info as $label => $values) {
            $batchInfo->set([
                'batch' => (string) $label,
                'queue' => $this->joined($values['queues']),
                'connection' => $this->joined($values['connections']),
            ], 1);
        }

        return [$jobClasses, $batchInfo];
    }

    /**
     * @param  array<array-key, true>  $values
     */
    private function joined(array $values): string
    {
        $values = array_map(strval(...), array_keys($values));
        sort($values, SORT_STRING);

        return implode(', ', $values);
    }
}
