<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Every table and caption that counts the attempts of the period ends each series at its last value inside the period,
 * so a series that stops anywhere inside it keeps its attempts. The end was the instant value at the period end
 * (Prometheus' 5-minute lookback, nothing after a staleness marker), so a series without one lost every attempt of the
 * period: one failed scrape at the end left the tables at 0 while the tiles counted 2,043 (AdversarialRound4StopTest
 * and SpecRound4Test hold the moved, renamed and decommissioned cases). The rows of the period tables are the queues
 * and the batch kinds' classes of the period for the same reason: a failed scrape of the only application also took its
 * queues (the pending-jobs gauge) and its kinds' classes away.
 *
 * Every panel as rendered, with the variables as Grafana sends them (Promtool), samples every 10 seconds. Grafana's
 * range queries (the tiles #8 A and #4 A, the graph #14) are emulated by a subquery at the graph step (1 minute) whose
 * points are Grafana's (multiples of the step, `end()` the last of them), summed. The expected values are the raw
 * samples' truth: per series, the last value inside the period minus the last one at or before its start, which the
 * tiles already show; after a counter reset the tables count the value after the reset (README, "Dashboard numbers").
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * Panel $id $refId of a render as Grafana sends it for the period ($to - $range, $to] (seconds after the start of the
 * input series), at 1-minute graph steps, every filter All.
 */
function seriesEndExpr(int $id, string $refId, int $to, int $range, LiveMode $mode = LiveMode::Poll): string
{
    $expr = Promtool::interpolate(Rendered::expressions(mode: $mode)['panel '.$id.' '.$refId], '.+', $to, $range);

    return str_replace('end()', (string) (intdiv($to, 60) * 60), $expr);
}

/**
 * A range query's points summed: Grafana's points from the step that holds the period start to the last one.
 */
function seriesEndPoints(string $expr, int $range): string
{
    return 'sum(sum_over_time(('.$expr.')['.($range + 60).'s:1m]))';
}

/**
 * One application's series: horizon status, the supervisor supervisor-1 and the queue default, a batch kind Import
 * running the class App\Jobs\Import, and the class's completed and failed counters.
 *
 * @param  array{completed: string, failed: string, other?: string}  $values  the counters' samples and the other
 *                                                                            series' (default: the completed
 *                                                                            samples' times at 1)
 * @return list<array{series: string, values: string}>
 */
function seriesEndApplication(string $job, array $values): array
{
    $labels = 'job="'.$job.'", instance="'.$job.':8080"';
    $other = $values['other'] ?? (string) preg_replace('/\d+\+\d+x/', '1+0x', $values['completed']);
    $counter = static fn (string $status): string => 'ho_queue_jobs_total{'.$labels.', connection="redis", queue="default", job_class="App\\\\Jobs\\\\Import", status="'.$status.'"}';

    return [
        ['series' => 'ho_horizon_status{'.$labels.'}', 'values' => $other],
        ['series' => 'ho_horizon_supervisor_processes{'.$labels.', supervisor="supervisor-1", queue="default"}', 'values' => $other],
        ['series' => 'ho_horizon_supervisor_queues{'.$labels.', supervisor="supervisor-1", queue="default", pool="default"}', 'values' => $other],
        ['series' => 'ho_queue_pending_jobs{'.$labels.', connection="redis", queue="default"}', 'values' => $other],
        ['series' => 'ho_queue_batch_created_timestamp_seconds{'.$labels.', batch="Import", name="Import 1"}', 'values' => $other],
        ['series' => 'ho_queue_batch_job_classes{'.$labels.', batch="Import", queue="default", job_class="App\\\\Jobs\\\\Import"}', 'values' => $other],
        ['series' => $counter('completed'), 'values' => $values['completed']],
        ['series' => $counter('failed'), 'values' => $values['failed']],
    ];
}

/**
 * Every table and caption that counts the attempts of the period ($completed and $failed attempts) or of the RPM
 * window ($rpm completed per minute), the row of the queue tables, and the tiles and #14 for reference ($steps
 * completed attempts, the reset step counting 0; default $completed).
 *
 * @return array<string, list<array{labels: string, value: float|int}>>
 */
function seriesEndPanels(int $to, int $range, int $completed, int $failed, int $rpm, ?int $steps = null): array
{
    $one = static fn (float|int $value, string $labels = '{}'): array => [['labels' => $labels, 'value' => $value]];
    $status = static fn (string $status): string => 'label_replace(vector(1), "status", "'.$status.'", "", "")';
    $expr = static fn (int $id, string $refId, LiveMode $mode = LiveMode::Poll): string => seriesEndExpr($id, $refId, $to, $range, $mode);
    $steps ??= $completed;

    return [
        // The row of the queue tables (#5 read the queue source #24: M the rows, C the attempts) and the Jobs table #6 B
        $expr(PanelRegistry::QUEUE_SOURCE, 'M') => $one(1, '{queue="default", supervisor="supervisor-1"}'),
        'sum(('.$expr(PanelRegistry::QUEUE_SOURCE, 'C').') and on (status) '.$status('completed').')' => $one($completed),
        'sum(('.$expr(PanelRegistry::QUEUE_SOURCE, 'C').') and on (status) '.$status('failed').')' => $one($failed),
        'sum(('.$expr(PanelRegistry::JOBS, 'B').') and on (status) '.$status('completed').')' => $one($completed),
        'sum(('.$expr(PanelRegistry::JOBS, 'B').') and on (status) '.$status('failed').')' => $one($failed),
        // The Failed tile: the Success caption and the threshold base (the finished attempts, at least 100)
        $expr(PanelRegistry::FAILED, 'B') => $one(floor(1000 * $completed / ($completed + $failed)) / 10),
        $expr(PanelRegistry::FAILED, 'C') => $one(max(100, $completed + $failed)),
        // The RPM caption of the Completed tile (--live=off)
        $expr(PanelRegistry::COMPLETED, 'B', LiveMode::Off) => $one($rpm),
        // The Batches table #17: failed, completed and RPM of the kind Import
        $expr(PanelRegistry::BATCH_WORKLOAD, 'D') => $one($failed, '{batch="Import"}'),
        $expr(PanelRegistry::BATCH_WORKLOAD, 'G') => $one($completed, '{batch="Import"}'),
        $expr(PanelRegistry::BATCH_WORKLOAD, 'H') => $one($rpm, '{batch="Import"}'),
        // For reference, per series step by step: the tiles and the graph #14 (per class and Total)
        seriesEndPoints($expr(PanelRegistry::COMPLETED, 'A'), $range) => $one($steps),
        seriesEndPoints($expr(PanelRegistry::FAILED, 'A'), $range) => $one($failed),
        seriesEndPoints($expr(PanelRegistry::COMPLETED_BY_JOB, 'A'), $range) => $one($steps),
        seriesEndPoints($expr(PanelRegistry::COMPLETED_BY_JOB, 'B'), $range) => $one($steps),
    ];
}

/**
 * @param  list<array{series: string, values: string}>  $input
 * @param  array<string, list<array{labels: string, value: float|int}>>  $expected  expression => samples at $to
 */
function seriesEndRun(array $input, int $to, array $expected): void
{
    // promtool loads the input samples only up to the last whole minute of the latest evaluation time
    $tests = [['expr' => 'vector(1)', 'eval_time' => ((intdiv($to, 60) + 1) * 60).'s', 'exp_samples' => [['labels' => '{}', 'value' => 1]]]];

    foreach ($expected as $expr => $samples) {
        $tests[] = ['expr' => $expr, 'eval_time' => $to.'s', 'exp_samples' => $samples];
    }

    [$status, $output] = Promtool::testRules([], [['interval' => '10s', 'input_series' => $input, 'promql_expr_test' => $tests]]);

    expect($status)->toBe(0, implode("\n", array_map(
        static fn (string $line): string => preg_match('/^\s*expr: /', $line) === 1 ? substr($line, 0, 90).'...' : $line,
        explode("\n", $output),
    )));
}

it('counts every attempt of the period after one failed scrape at its end', function (): void {
    // One application, one replica (the common setup); the scrape at the period end, 20m30s, failed: every series of
    // the target has a staleness marker then. Sample i at 10 i s: completed 2 i, failed i. The period (5m30s, 20m30s]:
    // completed 244 - 66 = 178, failed 122 - 33 = 89; the RPM window (15m30s, 20m30s]: round((244 - 186) / 5) = 12.
    // Grafana's last point 20m counts (20m, 20m30s], two scrapes. Before the fix the tables and captions counted
    // nothing (the instant values at the end are stale), the queue tables lost the row, the Batches table counted 0
    // and #14 lost its last step.
    seriesEndRun(
        seriesEndApplication('a', ['completed' => '0+2x122 stale', 'failed' => '0+1x122 stale']),
        1230,
        seriesEndPanels(1230, 900, completed: 178, failed: 89, rpm: 12),
    );
});

it('counts the attempts of a series that stopped long before the period end', function (string $stop): void {
    // Application a stops at 20m, 45 minutes before the end of the hour (5m, 65m]: beyond the 10-minute start lookback
    // and Prometheus' 5-minute lookback, so an end bounded by either loses it as the instant end did. Application b
    // (another scrape job: a's queue and class, or the dedicated job a moved to) runs throughout. Per scrape a
    // completes 2 and fails 1, b completes 1. a: completed 240 - 60 = 180, failed 90; b: completed 360. The RPM window
    // (60m, 65m] holds only b: round(30 / 5) = 6.
    $end = $stop === 'staleness marker' ? ' stale' : '';

    seriesEndRun(
        [
            ...seriesEndApplication('a', ['completed' => '0+2x120'.$end, 'failed' => '0+1x120'.$end]),
            ...seriesEndApplication('b', ['completed' => '1000+1x390', 'failed' => '7+0x390']),
        ],
        3900,
        seriesEndPanels(3900, 3600, completed: 540, failed: 90, rpm: 6),
    );
})->with(['staleness marker', 'no staleness marker (a scrape job removed)']);

it('counts the value after a reset of a series that stopped inside the period', function (): void {
    // The reset rule stays: after a reset the tables count the series' value after the reset. Application a's store is
    // flushed at 10m (completed 2 i up to 120 at 10m, then 1, 2, ... 60 at 20m) and a is decommissioned at 20m10s
    // (staleness markers). The period (5m30s, 20m30s]: the tables count 60, a's last value; the tiles and #14 count
    // every step but the reset one, (120 - 66) + (60 - 6) = 108. Failed is not reset: 120 - 33 = 87. The RPM window
    // (15m30s, 20m30s]: round((60 - 33) / 5) = 5.
    seriesEndRun(
        seriesEndApplication('a', ['completed' => '0+2x60 1+1x59 stale', 'failed' => '0+1x120 stale', 'other' => '1x120 stale']),
        1230,
        seriesEndPanels(1230, 900, completed: 60, failed: 87, rpm: 5, steps: 108),
    );
});
