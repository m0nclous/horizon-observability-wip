<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The Batch scope and the Queue filter of an application that stops inside the period (#38).
 *
 * The tables count every series up to its last value inside the period, and their queue rows come from the period. Two
 * inputs of the same queries read only the end of the period:
 * - the Batch scope (`<vector> unless on (job_class) <classes outside the selected kinds>`) took both of its class sets
 *   from instant selectors, so a class no longer exported at the end (its application stopped, or the last scrape
 *   failed) was in neither of them and counted under any selected kind;
 * - the queue variable listed the queues of the instant pending gauge, so after a reload Queue = All left out the
 *   queues of an application that stopped inside the period.
 * The scope now reads the classes of each scoped series from its own family, window and time, and the classes of the
 * selected kinds over the period; the queue variable lists the queues of the range.
 *
 * Here, samples every minute, the period (5m, 20m] evaluated at 20m: application a runs the kind Import (class Import
 * on the queue fast, 10 completed a minute: 150 in the period) throughout; application b runs the kind Export (class
 * Export on the queue slow, 10 completed a minute) and stops inside the period.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * Panel $id $refId (or the query of variable $id) of the package render as Grafana sends it at 20m with a 15-minute
 * range, with Batch = $kind (null: All) and every other filter All.
 */
function periodScopeExpr(int|string $id, string $refId = '', ?string $kind = null): string
{
    $expr = is_int($id)
        ? Rendered::expressions()['panel '.$id.' '.$refId]
        : substr(Rendered::expressions()['variable '.$id], strlen('query_result('), -1);

    if ($kind !== null) {
        $expr = strtr($expr, ['${batch_kind:text}' => $kind, '$batch_kind' => $kind]);
    }

    return Promtool::interpolate($expr, '.+', 20 * 60, 15 * 60);
}

/**
 * Application a throughout, application b with the samples $stop of its series (one per minute from 0m).
 *
 * @return list<array{series: string, values: string}>
 */
function periodScopeInput(string $stop): array
{
    $app = static fn (string $job, string $kind, string $queue, string $values, string $counter): array => [
        ['series' => 'ho_horizon_status{job="'.$job.'", instance="'.$job.':8080"}', 'values' => $values],
        ['series' => 'ho_queue_pending_jobs{job="'.$job.'", instance="'.$job.':8080", connection="redis", queue="'.$queue.'"}', 'values' => str_replace('1x', '0x', $values)],
        ['series' => 'ho_queue_batch_created_timestamp_seconds{job="'.$job.'", instance="'.$job.':8080", batch="'.$kind.'", name="'.$kind.' 1"}', 'values' => str_replace('1x', '60x', $values)],
        ['series' => 'ho_queue_batch_job_classes{job="'.$job.'", instance="'.$job.':8080", batch="'.$kind.'", queue="'.$queue.'", job_class="App\\\\Jobs\\\\'.$kind.'"}', 'values' => $values],
        ['series' => 'ho_queue_jobs_total{job="'.$job.'", instance="'.$job.':8080", connection="redis", queue="'.$queue.'", job_class="App\\\\Jobs\\\\'.$kind.'", status="completed"}', 'values' => $counter],
    ];

    return [
        ...$app('a', 'Import', 'fast', '1x20', '1000+10x20'),
        ...$app('b', 'Export', 'slow', '1x'.$stop, '100+10x'.$stop),
    ];
}

/**
 * Every table and tile that counts the completed attempts of the period, with Batch = $kind: $expected in (5m, 20m].
 *
 * @return list<array<string, mixed>>
 */
function periodScopeTests(string $kind, int $expected): array
{
    $one = static fn (int $value, string $labels = '{}'): array => [['labels' => $labels, 'value' => $value]];
    $completed = 'label_replace(vector(1), "status", "completed", "", "")';
    $points = static fn (string $expr): string => 'sum(sum_over_time(('.$expr.')[16m:1m]))';
    $tests = [
        // The queue tables (#5 read the queue source #24 C) and the Jobs table #6 B
        'sum(('.periodScopeExpr(PanelRegistry::QUEUE_SOURCE, 'C', $kind).') and on (status) '.$completed.')' => $one($expected),
        'sum(('.periodScopeExpr(PanelRegistry::JOBS, 'B', $kind).') and on (status) '.$completed.')' => $one($expected),
        // The Failed tile's threshold base: the finished attempts of the period, at least 100
        periodScopeExpr(PanelRegistry::FAILED, 'C', $kind) => $one(max(100, $expected)),
        // The Batches table #17: completed of the selected kind
        periodScopeExpr(PanelRegistry::BATCH_WORKLOAD, 'G', $kind) => $one($expected, '{batch="'.$kind.'"}'),
        // The Completed tile and the graph #14 (Total), their points summed
        $points(periodScopeExpr(PanelRegistry::COMPLETED, 'A', $kind)) => $one($expected),
        $points(periodScopeExpr(PanelRegistry::COMPLETED_BY_JOB, 'B', $kind)) => $one($expected),
    ];

    return array_map(
        static fn (string $expr, array $samples): array => ['expr' => $expr, 'eval_time' => '20m', 'exp_samples' => $samples],
        array_keys($tests),
        $tests,
    );
}

it('counts only the selected kind\'s classes when a class of another kind stops inside the period', function (string $stop, string $kind, int $expected): void {
    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => periodScopeInput($stop),
        'promql_expr_test' => periodScopeTests($kind, $expected),
    ]]);

    expect($status)->toBe(0, periodScopeOutput($output));
})->with([
    // b is decommissioned at 12m (staleness markers): Export is in neither instant set at 20m, so the render with
    // instant sets counted its 60 attempts in (5m, 11m] under Import (210)
    'decommissioned, Batch = Import' => ['11 stale', 'Import', 150],
    'decommissioned, Batch = Export' => ['11 stale', 'Export', 60],
    // b's last scrape failed (stale at 20m): the same, Export's 140 attempts up to 19m
    'last scrape failed, Batch = Import' => ['19 stale', 'Import', 150],
    'last scrape failed, Batch = Export' => ['19 stale', 'Export', 140],
]);

it('lists the queues of an application that stopped inside the range in the Queue filter', function (?string $kind, array $queues): void {
    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => periodScopeInput('11 stale'),
        'promql_expr_test' => [[
            'expr' => 'group by (queue) ('.periodScopeExpr('queue', kind: $kind).')',
            'eval_time' => '20m',
            'exp_samples' => array_map(static fn (string $queue): array => ['labels' => '{queue="'.$queue.'"}', 'value' => 1], $queues),
        ]],
    ]]);

    expect($status)->toBe(0, periodScopeOutput($output));
})->with([
    // Queue = All is this list: the render with the instant pending gauge listed fast only
    'Batch All' => [null, ['fast', 'slow']],
    'Batch = Import' => ['Import', ['fast']],
    'Batch = Export' => ['Export', ['slow']],
]);

/**
 * promtool's output without the long expressions.
 */
function periodScopeOutput(string $output): string
{
    return implode("\n", array_map(
        static fn (string $line): string => preg_match('/^\s*expr: /', $line) === 1 ? substr($line, 0, 70).'...' : $line,
        explode("\n", $output),
    ));
}
