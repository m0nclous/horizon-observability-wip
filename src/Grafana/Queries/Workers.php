<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Queries;

use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Thresholds;
use DF\HorizonObservability\Metrics\Metric;

/**
 * Worker processes, supervisor pools and their utilisation.
 *
 * Utilisation is per supervisor pool, not per queue: the busy workers of all its queues (averaged over the busy
 * window) over its maxProcesses. Otherwise two queues of one supervisor at 50% each would look half idle while
 * together they hit the ceiling. The reserved series of a class exists only while the class runs, so the
 * average is the sum of samples over the number of scrapes, counted on the supervisors' queue series that always
 * exist.
 * The filters do not apply (`queue=~".+"`, no job filter): a selected queue still counts its whole pool.
 *
 * Which queues a supervisor works comes from `horizon_supervisor_queues` (supervisor, queue, pool), not from the
 * `queue` label of `horizon_supervisor_processes`: that label is the pool label, the comma-joined queue list of a
 * balance=false supervisor (`mail,sms`), which matches neither a queue name nor the Queue filter.
 *
 * @internal
 */
final readonly class Workers
{
    /**
     * The processes tile encodes the utilisation zone of a supervisor in the fraction: n + zone / 64. Multiples
     * of 1/64 are exact in binary, so the text of the value equals the mapping key.
     */
    public const ZONE_DIVISOR = 64;

    /**
     * The highest zone (red): above the critical load, or Horizon not running.
     */
    private const MAX_ZONE = 2;

    public function __construct(
        private Promql $promql,
        private Selection $selection,
        private string $busyWindow,
    ) {}

    /**
     * `(min(horizon_status) >= bool 1)`: 1 while Horizon runs (legacy `HORIZON_RUNNING`).
     */
    public function horizonRunning(): string
    {
        return Promql::paren(Promql::min($this->promql->selector(Metric::HorizonStatus)).' >= bool 1');
    }

    /**
     * 1 when Horizon is paused or stopped at the end of the period, else 0 (legacy `HORIZON_STOPPED_AT_END`).
     */
    public function horizonStoppedAtEnd(): string
    {
        $status = Promql::min($this->promql->shifted(Metric::HorizonStatus, [], at: Promql::TO_SECONDS));

        return Promql::paren(Promql::either(Promql::paren($status.' < bool 1'), 'vector(0)'));
    }

    /**
     * Busy share of the workers of each queue over the busy window (legacy `QUEUE_BUSY`): the reserved jobs summed
     * over the window, divided by the number of scrapes in it, counted on the supervisors' queue series of the queue,
     * which exist for every queue a running supervisor works (the pilot counted `horizon_current_processes`, whose
     * pooled queue label left out the queues of a balance=false pool).
     */
    public function queueBusy(): string
    {
        $anyQueue = Promql::re('queue', '.+');

        return Promql::paren(Promql::binary(
            Promql::sum($this->promql->over('sum_over_time', Metric::QueueJobsReserved, $this->busyWindow, [$anyQueue]), 'queue'),
            '/',
            Promql::max($this->promql->over('count_over_time', Metric::SupervisorQueues, $this->busyWindow, [$anyQueue]), 'queue'),
            on: ['queue'],
        ));
    }

    /**
     * Which queues each supervisor works, one series per (supervisor, queue name) (legacy `SUPERVISOR_QUEUES`).
     */
    public function supervisorQueues(): string
    {
        return Promql::group($this->promql->selector(Metric::SupervisorQueues), 'supervisor', 'queue');
    }

    /**
     * The selected queues of each selected supervisor (Supervisor and Queue filters), one series per (supervisor,
     * queue name).
     */
    public function selectedSupervisorQueues(): string
    {
        return Promql::group($this->promql->selector(
            Metric::SupervisorQueues,
            Promql::re('supervisor', '$supervisor'),
            Promql::re('queue', '$queue'),
        ), 'supervisor', 'queue');
    }

    /**
     * The processes of the selected supervisors' pools that work a selected queue, per (supervisor, pool label): a
     * balance=false pool `mail,sms` counts once when mail or sms or both are selected.
     */
    public function selectedProcesses(): string
    {
        $supervisor = Promql::re('supervisor', '$supervisor');
        $pools = Promql::labelReplace(
            Promql::group($this->promql->selector(Metric::SupervisorQueues, $supervisor, Promql::re('queue', '$queue')), 'supervisor', 'pool'),
            'queue',
            '$1',
            'pool',
            '(.*)',
        );

        return Promql::andOn($this->promql->selector(Metric::SupervisorProcesses, $supervisor), $pools, 'supervisor', 'queue');
    }

    /**
     * The running processes that work each selected queue (Queue filter), over every supervisor: the processes of
     * every pool whose queues include it, so each queue of a balance=false pool counts the whole pool, as the live
     * snapshot's `workload` does.
     */
    public function queueProcesses(): string
    {
        $pools = Promql::group($this->promql->selector(Metric::SupervisorQueues, Promql::re('queue', '$queue')), 'supervisor', 'queue', 'pool');
        $processes = Promql::sum(
            Promql::labelReplace($this->promql->selector(Metric::SupervisorProcesses), 'pool', '$1', 'queue', '(.*)'),
            'supervisor',
            'pool',
        );

        return Promql::sum(Promql::binary($pools, '*', $processes, on: ['supervisor', 'pool'], groupLeft: []), 'queue');
    }

    /**
     * Utilisation of each supervisor pool, 0..1 (legacy `SUPERVISOR_UTIL`).
     */
    public function supervisorUtilisation(): string
    {
        $busy = Promql::sum(Promql::binary($this->supervisorQueues(), '*', $this->queueBusy(), on: ['queue'], groupLeft: []), 'supervisor');
        $idle = '0 * '.Promql::group($this->supervisorQueues(), 'supervisor');
        $capacity = Promql::max($this->promql->selector(Metric::SupervisorMaxProcesses), 'supervisor').' > 0';

        return Promql::call(
            'clamp_max',
            Promql::binary(Promql::paren(Promql::orOn($busy, $idle, 'supervisor')), '/', Promql::paren($capacity), on: ['supervisor']),
            '1',
        );
    }

    /**
     * The utilisation exactly at the end of the period, so the queue table (instant query) and the colour of the
     * processes tile (range query, whose last point is a fraction of a step earlier) agree
     * (legacy `SUPERVISOR_UTIL_AT_END`).
     */
    public function supervisorUtilisationAtEnd(): string
    {
        return Promql::range('last_over_time', Promql::paren($this->supervisorUtilisation()), '1s:1s', at: Promql::TO_SECONDS);
    }

    /**
     * The utilisation of the pool that works each queue (legacy `SUPERVISOR_LOAD`).
     */
    public function queueLoad(): string
    {
        return Promql::max(
            Promql::binary($this->supervisorQueues(), '*', $this->supervisorUtilisationAtEnd(), on: ['supervisor'], groupLeft: []),
            'queue',
        );
    }

    /**
     * Utilisation zone of each supervisor: 0 below the warning load, 1 below the critical load, 2 above it or
     * while Horizon is not running (legacy `PROCESSES_ZONE`).
     */
    public function processesZone(): string
    {
        $above = fn (int $percent): string => Promql::paren(
            Promql::call('round', '100 * '.$this->supervisorUtilisationAtEnd()).' >= bool '.$percent,
        );
        $zone = Promql::orOn(
            $above(Thresholds::LOAD_WARNING_PERCENT).' + '.$above(Thresholds::LOAD_CRITICAL_PERCENT),
            '0 * '.Promql::group($this->promql->selector(Metric::SupervisorProcesses), 'supervisor'),
            'supervisor',
        );

        return Promql::call(
            'clamp_max',
            Promql::binary(Promql::paren($zone), '+', self::MAX_ZONE.' * '.$this->horizonStoppedAtEnd(), on: [], groupLeft: []),
            (string) self::MAX_ZONE,
        );
    }

    /**
     * Processes tile value per supervisor: the running processes of its pools that work a selected queue (0 while
     * Horizon is paused or stopped), or with a batch or jobs selected the workers busy with their jobs in the selected
     * queues, 0 for a supervisor without such workers; plus the zone in the fraction (legacy `PROCESSES_VALUE`).
     */
    public function processesValue(): string
    {
        $queues = $this->selectedSupervisorQueues();
        // The panel keeps the Job filter out of its other values, so the selected jobs and their batch scope are
        // explicit here
        $reserved = Promql::sum($this->promql->batchScoped(
            $this->promql->selector(Metric::QueueJobsReserved, Promql::re('job_class', '$job_class')),
        ), 'queue');

        $value = $this->selection->byJobSelection(
            Promql::sum($this->selectedProcesses(), 'supervisor'),
            // queue_jobs_reserved exists only for classes with reserved jobs: without the zero a supervisor that runs
            // none of the selected jobs would have no value, a dash
            Promql::orOn(
                Promql::sum(Promql::binary($queues, '*', $reserved, on: ['queue'], groupLeft: []), 'supervisor'),
                '0 * '.Promql::group($queues, 'supervisor'),
                'supervisor',
            ),
        );

        return Promql::binary(
            Promql::binary(Promql::paren($value), '*', $this->horizonRunning(), on: [], groupLeft: []),
            '+',
            Promql::paren($this->processesZone().' / '.self::ZONE_DIVISOR),
            on: ['supervisor'],
        );
    }

    /**
     * The worker limit of the selected queues: the maxProcesses of their supervisors. Queues of one supervisor
     * share one limit (legacy `WORKER_LIMIT`).
     */
    public function workerLimit(): string
    {
        $queues = Promql::group($this->promql->selector(Metric::SupervisorQueues, Promql::re('queue', '$queue')), 'supervisor');

        return Promql::max(Promql::binary($this->promql->selector(Metric::SupervisorMaxProcesses), '*', $queues, on: ['supervisor'], groupLeft: []));
    }
}
