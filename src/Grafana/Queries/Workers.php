<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Queries;

use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Thresholds;

/**
 * Worker processes, supervisor pools and their utilisation.
 *
 * Utilisation is per supervisor pool, not per queue: the busy workers of all its queues (averaged over the busy
 * window) over its maxProcesses. Otherwise two queues of one supervisor at 50% each would look half idle while
 * together they hit the ceiling. The reserved series of a class exists only while the class runs, so the
 * average is the sum of samples over the number of scrapes, counted on the processes series that always exists.
 * The filters do not apply (`queue=~".+"`, no job filter): a selected queue still counts its whole pool.
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
        return Promql::paren(Promql::min($this->promql->selector('horizon_status')).' >= bool 1');
    }

    /**
     * 1 when Horizon is paused or stopped at the end of the period, else 0 (legacy `HORIZON_STOPPED_AT_END`).
     */
    public function horizonStoppedAtEnd(): string
    {
        $status = Promql::min(Promql::at($this->promql->selector('horizon_status'), Promql::TO_SECONDS));

        return Promql::paren(Promql::either(Promql::paren($status.' < bool 1'), 'vector(0)'));
    }

    /**
     * Busy share of the workers of each queue over the busy window (legacy `QUEUE_BUSY`).
     */
    public function queueBusy(): string
    {
        $anyQueue = Promql::re('queue', '.+');

        return Promql::paren(Promql::binary(
            Promql::sum(Promql::range('sum_over_time', $this->promql->selector('queue_jobs_reserved', $anyQueue), $this->busyWindow), 'queue'),
            '/',
            Promql::max(Promql::range('count_over_time', $this->promql->selector('horizon_current_processes', $anyQueue), $this->busyWindow), 'queue'),
            on: ['queue'],
        ));
    }

    /**
     * Which queues each supervisor works (legacy `SUPERVISOR_QUEUES`).
     */
    public function supervisorQueues(): string
    {
        return Promql::group($this->promql->selector('horizon_supervisor_processes'), 'supervisor', 'queue');
    }

    /**
     * Utilisation of each supervisor pool, 0..1 (legacy `SUPERVISOR_UTIL`).
     */
    public function supervisorUtilisation(): string
    {
        $busy = Promql::sum(Promql::binary($this->supervisorQueues(), '*', $this->queueBusy(), on: ['queue'], groupLeft: []), 'supervisor');
        $idle = '0 * '.Promql::group($this->supervisorQueues(), 'supervisor');
        $capacity = Promql::max($this->promql->selector('horizon_supervisor_max_processes'), 'supervisor').' > 0';

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
            '0 * '.Promql::group($this->promql->selector('horizon_supervisor_processes'), 'supervisor'),
            'supervisor',
        );

        return Promql::call(
            'clamp_max',
            Promql::binary(Promql::paren($zone), '+', self::MAX_ZONE.' * '.$this->horizonStoppedAtEnd(), on: [], groupLeft: []),
            (string) self::MAX_ZONE,
        );
    }

    /**
     * Processes tile value per supervisor: the running processes (0 while Horizon is paused or stopped), or with
     * a batch or jobs selected the workers busy with their jobs, plus the zone in the fraction
     * (legacy `PROCESSES_VALUE`).
     */
    public function processesValue(): string
    {
        $selected = [Promql::re('supervisor', '$supervisor'), Promql::re('queue', '$queue')];
        $processes = $this->promql->selector('horizon_supervisor_processes', ...$selected);
        $reserved = Promql::sum($this->promql->selector('queue_jobs_reserved', Promql::re('job_class', '$job_class')), 'queue');

        $value = $this->selection->byJobSelection(
            Promql::sum($processes, 'supervisor'),
            Promql::sum(Promql::binary(Promql::group($processes, 'supervisor', 'queue'), '*', $reserved, on: ['queue'], groupLeft: []), 'supervisor'),
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
        $queues = Promql::group($this->promql->selector('horizon_supervisor_processes', Promql::re('queue', '$queue')), 'supervisor');

        return Promql::max(Promql::binary($this->promql->selector('horizon_supervisor_max_processes'), '*', $queues, on: ['supervisor'], groupLeft: []));
    }
}
