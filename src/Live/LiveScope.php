<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live;

use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Http\JobListQuery;
use DF\HorizonObservability\Redis\Keys;

/**
 * Resolved scope of a live snapshot (Addendum R4.1-R4.3, C'): the queues it covers and whether a Job or Batch
 * filter is active.
 *
 * - Supervisor and Queue scope the queues: the selected running supervisors' split queues intersected with the
 *   topology and the queue list.
 * - A Batch filter narrows the queues to the queues of its batch links and the classes to the linked classes.
 * - A Job or Batch filter sets `jobFilter`: pending and delayed values become null and busy workers count only the
 *   selected classes.
 *
 * An empty queue list is a valid scope: the snapshot is all zeros and costs no queue Redis calls.
 *
 * @internal
 */
final readonly class LiveScope
{
    /**
     * @param  list<string>|null  $supervisors  the Supervisor filter; null = all
     * @param  list<array{connection: string, queue: string}>  $queues  the queues in scope, in topology order
     * @param  bool  $jobFilter  a Job or Batch filter is active
     * @param  list<string>|null  $jobClasses  classes busy workers are counted for; null = all
     */
    public function __construct(public ?array $supervisors, public array $queues, public bool $jobFilter, public ?array $jobClasses) {}

    /**
     * The whole application: every topology queue, no filter (the stream command's scope).
     */
    public static function full(Topology $topology): self
    {
        return new self(null, $topology->queues(), false, null);
    }

    /**
     * The scope of `/live` filters ({@see JobListQuery::filtersFromRequest()}). Reads
     * the running supervisors only with a Supervisor filter and the batch links only with a Batch filter.
     *
     * @param  array{supervisors: list<string>|null, batchKinds: list<string>|null, queues: list<string>|null, jobClasses: list<string>|null}  $filters
     */
    public static function fromFilters(array $filters, Topology $topology, Supervisors $supervisors, BatchLinks $links): self
    {
        $queues = $topology->queues();
        $jobClasses = $filters['jobClasses'];

        if ($filters['supervisors'] !== null) {
            $served = [];

            foreach ($supervisors->all() as $supervisor) {
                if (in_array($supervisor->name, $filters['supervisors'], true)) {
                    foreach ($supervisor->queues as $queue) {
                        $served[Keys::field($supervisor->connection, $queue)] = true;
                    }
                }
            }

            $queues = array_filter($queues, static fn (array $queue): bool => isset($served[Keys::field($queue['connection'], $queue['queue'])]));
        }

        if ($filters['queues'] !== null) {
            $selected = $filters['queues'];
            $queues = array_filter($queues, static fn (array $queue): bool => in_array($queue['queue'], $selected, true));
        }

        if ($filters['batchKinds'] !== null) {
            $batch = $links->forKinds($filters['batchKinds']);
            $queues = array_filter($queues, static fn (array $queue): bool => in_array($queue['queue'], $batch['queues'], true));
            $jobClasses = $jobClasses === null ? $batch['jobs'] : array_values(array_intersect($jobClasses, $batch['jobs']));
        }

        return new self(
            $filters['supervisors'],
            array_values($queues),
            $filters['jobClasses'] !== null || $filters['batchKinds'] !== null,
            $jobClasses,
        );
    }

    /**
     * Cache key part of the scope: sha1 of the normalised (sorted) scope. `serialize()` is exact for any bytes (a
     * JSON encoding fails on invalid UTF-8, and every such scope would share one key).
     */
    public function cacheKey(): string
    {
        $supervisors = $this->supervisors;
        $classes = $this->jobClasses;
        $queues = array_map(static fn (array $queue): string => Keys::field($queue['connection'], $queue['queue']), $this->queues);

        if ($supervisors !== null) {
            $supervisors = array_values(array_unique($supervisors));
            sort($supervisors);
        }

        if ($classes !== null) {
            $classes = array_values(array_unique($classes));
            sort($classes);
        }

        $queues = array_values(array_unique($queues));
        sort($queues);

        return sha1(serialize([1, $supervisors, $queues, $this->jobFilter, $classes]));
    }

    /**
     * The queue names in scope without duplicates (the `scope.queues` field).
     *
     * @return list<string>
     */
    public function queueNames(): array
    {
        return array_values(array_unique(array_map(static fn (array $queue): string => $queue['queue'], $this->queues)));
    }
}
