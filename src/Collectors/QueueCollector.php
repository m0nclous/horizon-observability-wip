<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Metrics\Labels;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Queues\QueueInspector;
use DF\HorizonObservability\Queues\QueueSnapshot;
use DF\HorizonObservability\Queues\UnsupportedTopology;
use RuntimeException;
use Throwable;

/**
 * Collector `queues`: queue_pending_jobs, queue_delayed_*, queue_oldest_pending_job_age_seconds,
 * queue_jobs_pending|reserved.
 *
 * - **Queues** are the ones `php artisan horizon` runs in the effective environment ({@see Topology::queues()}),
 *   read from Horizon's config, so they are exported while Horizon is down. Queues of connections that do not use
 *   the `redis` driver are skipped. Every queue costs one `queue_state.lua` call ({@see QueueInspector::inspect()}).
 * - **Isolation per queue.** A queue that fails (a script or connection error, a cluster queue without a hash tag:
 *   {@see UnsupportedTopology}) is reported with {@see ScrapeContext::reportFailure()} and has no series; the other
 *   queues are exported. When the scrape budget runs out between two queues the rest are skipped and reported.
 * - **Values.** Pending includes the due delayed jobs; delayed includes them too (see queue_delayed_due_jobs). The
 *   oldest age is omitted when it is unknown (jobs are ready but no fresh one was scanned and nothing is due).
 * - **Classes**: the top {@see self::CLASS_LIMIT} classes per queue by count, the rest folded into `(other)`;
 *   the ready jobs beyond the scan limit or the byte cap, and the reserved jobs beyond the reserved scan limit, are
 *   `(unscanned)`. An empty queue has no per-class series.
 *
 * @internal
 */
final readonly class QueueCollector implements Collector
{
    /**
     * Job classes kept per queue and family (an internal constant); the rest become `(other)`.
     */
    public const CLASS_LIMIT = 100;

    public function __construct(
        private QueueInspector $inspector,
        private Topology $topology,
    ) {}

    public function name(): string
    {
        return 'queues';
    }

    public function collect(ScrapeContext $context): iterable
    {
        /** @var list<array{connection: string, queue: string}> $queues */
        $queues = $context->remember(HorizonCollector::TOPOLOGY, fn (): array => $this->topology->queues());
        $now = $context->now();
        $timestamp = (float) $now->format('U.u');

        $pending = MetricFamily::of(Metric::QueuePendingJobs);
        $delayed = MetricFamily::of(Metric::QueueDelayedJobs);
        $due = MetricFamily::of(Metric::QueueDelayedDueJobs);
        $oldest = MetricFamily::of(Metric::QueueOldestPendingJobAge);
        $pendingByClass = MetricFamily::of(Metric::QueueJobsPending);
        $reservedByClass = MetricFamily::of(Metric::QueueJobsReserved);
        $read = 0;

        foreach ($queues as ['connection' => $connection, 'queue' => $queue]) {
            if (! $this->inspector->supports($connection)) {
                continue;
            }

            if ($context->deadlineExceeded()) {
                $context->reportFailure('budget', new RuntimeException(sprintf(
                    'the scrape budget ran out after %d of %d queues; the rest were not read.',
                    $read,
                    count($queues),
                )));

                break;
            }

            $read++;

            try {
                $snapshot = $this->inspector->inspect($connection, $queue, $now);
            } catch (Throwable $e) {
                $context->reportFailure('queue:'.$connection.':'.$queue, $e);

                continue;
            }

            $labels = ['connection' => $connection, 'queue' => $queue];
            $pending->set($labels, $snapshot->pending());
            $delayed->set($labels, $snapshot->delayed);
            $due->set($labels, $snapshot->delayedDue);
            $age = $snapshot->oldestAgeSeconds($timestamp);

            if ($age !== null) {
                $oldest->set($labels, round($age, 3));
            }

            foreach ($this->classes($snapshot->pendingByClass, $snapshot->unscannedPending()) as $class => $count) {
                $pendingByClass->set([...$labels, 'job_class' => (string) $class], $count);
            }

            foreach ($this->classes($snapshot->reservedByClass, $snapshot->unscannedReserved()) as $class => $count) {
                $reservedByClass->set([...$labels, 'job_class' => (string) $class], $count);
            }
        }

        return array_values(array_filter(
            [$pending, $delayed, $due, $oldest, $pendingByClass, $reservedByClass],
            static fn (MetricFamily $family): bool => ! $family->isEmpty(),
        ));
    }

    /**
     * Counts per class label: the top {@see self::CLASS_LIMIT} plus `(other)`, plus `(unscanned)`.
     *
     * @param  array<string, int>  $counts  normalised class => count ({@see QueueSnapshot})
     * @return array<string, int>
     */
    private function classes(array $counts, int $unscanned): array
    {
        $classes = Labels::topN($counts, self::CLASS_LIMIT);

        if ($unscanned > 0) {
            $classes[Labels::UNSCANNED] = ($classes[Labels::UNSCANNED] ?? 0) + $unscanned;
        }

        return $classes;
    }
}
