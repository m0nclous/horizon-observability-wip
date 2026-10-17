<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\JobStore;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Queues\DelayedJobStore;
use DF\HorizonObservability\Redis\Keys;

/**
 * The rows of a job list (Arch Q2.1, C13, C14): resolves the dashboard filters to queue and class sets, reads the
 * rows from {@see JobStore} or {@see DelayedJobStore} and formats them with {@see JobRows}.
 *
 * - Supervisor: the queues of the selected running supervisors, split from their `queue` option, so a
 *   `balance=false` pool (whose process label is `mail,sms`) matches the single queue of each job (C14). The
 *   running supervisors are read only when this filter is set.
 * - Batch: the queues and classes of the selected batch kinds' links ({@see BatchLinks::forKinds()}), read only
 *   when this filter is set and the other filters can still match.
 * - Queue and Job: intersected with the above; values compare with Horizon's `queue` field and the normalised job
 *   name.
 * - An empty resolved filter (a supervisor that is not running, a batch kind without links, disjoint filters)
 *   returns no rows without reading any job (C13).
 * - Delayed jobs are read from the queues of the Horizon topology that pass the filters (with a Supervisor filter,
 *   only the queues those supervisors run on their connection), against one `now` per request.
 *
 * @internal
 */
final readonly class JobListService
{
    public function __construct(
        private Supervisors $supervisors,
        private Topology $topology,
        private BatchLinks $links,
        private JobStore $jobs,
        private DelayedJobStore $delayed,
        private Config $config,
    ) {}

    /**
     * The formatted rows of a list ({@see JobRows::fields()}).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(JobListQuery $query): array
    {
        $queues = $query->queues;
        $jobClasses = $query->jobClasses;
        $served = null;

        if ($query->supervisors !== null) {
            $served = $this->served($query->supervisors);
            $queues = $this->intersect($queues, array_column($served, 'queue'));
        }

        if ($query->batchKinds !== null && $queues !== [] && $jobClasses !== []) {
            $links = $this->links->forKinds($query->batchKinds);
            $queues = $this->intersect($queues, $links['queues']);
            $jobClasses = $this->intersect($jobClasses, $links['jobs']);
        }

        if ($queues === [] || $jobClasses === []) {
            return [];
        }

        if ($query->list === JobList::Delayed) {
            $rows = $this->delayed->rows($this->delayedQueues($queues, $served), $jobClasses, $query->limit, CarbonImmutable::now());

            return array_map(JobRows::delayed(...), $rows);
        }

        $mode = $this->config->exceptions;

        return array_map(
            static fn (array $row): array => JobRows::horizon($query->list, $row, $mode),
            $this->jobs->rows($query, $queues, $jobClasses),
        );
    }

    /**
     * The connection and queue pairs the selected running supervisors work on.
     *
     * @param  list<string>  $names  supervisor names without the master prefix, as in the metric labels
     * @return list<array{connection: string, queue: string}>
     */
    private function served(array $names): array
    {
        $served = [];

        foreach ($this->supervisors->all() as $supervisor) {
            if (! in_array($supervisor->name, $names, true)) {
                continue;
            }

            foreach ($supervisor->queues as $queue) {
                $served[Keys::field($supervisor->connection, $queue)] = ['connection' => $supervisor->connection, 'queue' => $queue];
            }
        }

        return array_values($served);
    }

    /**
     * The topology queues in scope of the delayed list.
     *
     * @param  list<string>|null  $queues  null = all
     * @param  list<array{connection: string, queue: string}>|null  $served  null = no Supervisor filter
     * @return list<array{connection: string, queue: string}>
     */
    private function delayedQueues(?array $queues, ?array $served): array
    {
        $pairs = $served === null ? null : array_fill_keys(array_map(static fn (array $pair): string => Keys::field($pair['connection'], $pair['queue']), $served), true);

        return array_values(array_filter(
            $this->topology->queues(),
            static fn (array $queue): bool => ($queues === null || in_array($queue['queue'], $queues, true))
                && ($pairs === null || isset($pairs[Keys::field($queue['connection'], $queue['queue'])])),
        ));
    }

    /**
     * The filter narrowed to the values, in filter order without duplicates; the values themselves without a filter.
     *
     * @param  list<string>|null  $filter
     * @param  list<string>  $values
     * @return list<string>
     */
    private function intersect(?array $filter, array $values): array
    {
        if ($filter === null) {
            return array_values(array_unique($values));
        }

        $allowed = array_fill_keys($values, true);

        return array_values(array_unique(array_filter($filter, static fn (string $value): bool => isset($allowed[$value]))));
    }
}
