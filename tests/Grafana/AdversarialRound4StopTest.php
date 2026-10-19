<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * A series that stops inside the range. Since the per-series start (5075855), Attempts::delta() counts each series
 * (job, connection, queue, job_class, status) from its own start to its own end, then sums by (queue, job_class,
 * status). The start has a lookback and a fallback (the first value in the range); the end is the instant value at the
 * period end (5-minute lookback, and none after a staleness marker), with no fallback. So a series without a value at
 * the period end loses every attempt it made inside the range: the queue tables (#5 read #24 C), the Jobs table #6 B,
 * the Success caption and threshold base of the Failed tile (#4 B/C) and the Batches columns (#17) leave it out, while
 * the tiles (#8 A, #4 A, Attempts::perStepTotal()) and the per-step graph #14 count it step by step.
 *
 * A series stops when its scrape job is removed or renamed, when the application moves to the scrape job of its own
 * that the README recommends, when a Redis connection is renamed and when an application is decommissioned: after a
 * configuration reload Prometheus writes no staleness markers for a removed job, so its series fall out of the instant
 * vector's 5-minute lookback 5 minutes after their last sample. A target that service discovery removes, and every
 * failed scrape of a single-replica application (a deploy, a timeout), writes staleness markers at once (the replica
 * dedupe keeps a series only while another replica answers). Verified on the containerised dev stack (10 s scrapes,
 * Grafana 13.2.4 and 12.4.13 through /api/ds/query): renaming the scrape job, 5 minutes later the queue tables showed
 * 2,054 completed and 16 failed for the last 15 minutes, the tiles and the raw samples 4,259 and 37 (for 1 hour, the
 * old job alone: 0 in the tables, 2,205 on the tile and in the samples); with the application paused until a scrape
 * failed, the tables showed 0 and the tiles 2,043, the raw-sample count.
 *
 * In the "moved" case the earlier form (the sum by (queue, job_class, status) before the difference) was exact: the new
 * job's series continue the old job's values in the same sum, so the sum's start and end match (300: the storage's 150
 * and c's 150). The per-series start drops the old job's part of the range: 230 where the tiles count 290.
 *
 * Every panel as rendered (the package render), evaluated with the variables as Grafana sends them (Promtool), at
 * 20m for the range (5m, 20m], samples every minute. Application c runs throughout and completes 10 jobs a minute
 * (150 in the range). The expected values are the per-series attempts in the range (a series that appears
 * counts from its first value), which the tiles already show: what a table must show to agree with the tiles.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * Panel $id $refId of the package render as Grafana sends it at 20m with a 15-minute range, every filter All.
 */
function round4StopExpr(int $id, string $refId): string
{
    return Promtool::interpolate(Rendered::expressions()['panel '.$id.' '.$refId], '.+', 20 * 60, 15 * 60);
}

/**
 * The input series: application c throughout, plus $members (job => [connection, samples]) on the same queue, class
 * and status; batches of the kind Import run the class Import (the Batches table #17).
 *
 * @param  array<string, array{0: string, 1: string, 2: string}>  $members  key => [job, connection, samples]
 * @return list<array{series: string, values: string}>
 */
function round4StopInput(array $members): array
{
    $series = static fn (string $job, string $connection): string => sprintf(
        'ho_queue_jobs_total{job="%s", instance="%s:8080", connection="%s", queue="default", job_class="App\\\\Jobs\\\\Import", status="completed"}',
        $job,
        $job,
        $connection,
    );
    $input = [
        ['series' => 'ho_horizon_status{job="c", instance="c:8080"}', 'values' => '1x20'],
        ['series' => 'ho_queue_pending_jobs{job="c", instance="c:8080", connection="redis", queue="default"}', 'values' => '0x20'],
        ['series' => 'ho_queue_batch_created_timestamp_seconds{job="c", instance="c:8080", batch="Import", name="Import 1"}', 'values' => '60x20'],
        ['series' => 'ho_queue_batch_job_classes{job="c", instance="c:8080", batch="Import", queue="default", job_class="App\\\\Jobs\\\\Import"}', 'values' => '1x20'],
        ['series' => $series('c', 'redis'), 'values' => '1000+10x20'],
    ];

    foreach ($members as [$job, $connection, $values]) {
        $input[] = ['series' => $series($job, $connection), 'values' => $values];
    }

    return $input;
}

/**
 * Every table and caption that counts the attempts of the range, and the tiles and #14 for reference, with $expected
 * completed attempts in (5m, 20m].
 *
 * @return list<array<string, mixed>>
 */
function round4StopTests(int $expected): array
{
    $one = static fn (int $value, string $labels = '{}'): array => [['labels' => $labels, 'value' => $value]];
    $completed = 'label_replace(vector(1), "status", "completed", "", "")';
    $points = static fn (string $expr): string => 'sum(sum_over_time(('.$expr.')[16m:1m]))';
    $tests = [
        // The queue tables (#5 read the queue source #24 C) and the Jobs table #6 B
        'sum(('.round4StopExpr(PanelRegistry::QUEUE_SOURCE, 'C').') and on (status) '.$completed.')' => $one($expected),
        'sum(('.round4StopExpr(PanelRegistry::JOBS, 'B').') and on (status) '.$completed.')' => $one($expected),
        // The Failed tile's threshold base: the finished attempts of the range
        round4StopExpr(PanelRegistry::FAILED, 'C') => $one($expected),
        // The Batches table #17: completed of the kind Import
        round4StopExpr(PanelRegistry::BATCH_WORKLOAD, 'G') => $one($expected, '{batch="Import"}'),
        // For reference, already per series step by step: the Completed tile and the graph #14 (Total)
        $points(round4StopExpr(PanelRegistry::COMPLETED, 'A')) => $one($expected),
        $points(round4StopExpr(PanelRegistry::COMPLETED_BY_JOB, 'B')) => $one($expected),
    ];

    return array_map(
        static fn (string $expr, array $samples): array => ['expr' => $expr, 'eval_time' => '20m', 'exp_samples' => $samples],
        array_keys($tests),
        $tests,
    );
}

it('counts the attempts of a series that stops inside the range in every table, as the tiles do', function (array $members, int $expected): void {
    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => round4StopInput($members),
        'promql_expr_test' => round4StopTests($expected),
    ]]);

    expect($status)->toBe(0, round4StopOutput($output));
})->with([
    // The application moves from scrape job a to the dedicated job b at 12m: a's last sample is at 11m, b exports the
    // same counter from 12m. a: 150 -> 210 (60), b: 220 -> 300 (80), c: 150. The committed render's tables count 230;
    // the render before per-series starts counted 300.
    'the scrape job replaced by another one (moved, renamed)' => [[
        ['a', 'redis', '100+10x11'],
        ['b', 'redis', '_x12 220+10x8'],
    ], 290],
    // The same with the Redis connection renamed inside one scrape job
    'the connection renamed' => [[
        ['a', 'redis', '100+10x11'],
        ['a', 'horizon', '_x12 220+10x8'],
    ], 290],
    // Application a is decommissioned at 12m, its target removed by service discovery (staleness markers): its 60
    // attempts before that are left out (the tables count 150)
    'an application decommissioned inside the range' => [[
        ['a', 'redis', '100+10x11 stale'],
    ], 210],
    // The last scrape of application a failed (a deploy, a timeout): stale at 20m, 140 attempts up to 19m are left
    // out for one scrape interval (the tables count 150)
    'a failed scrape at the period end' => [[
        ['a', 'redis', '100+10x19 stale'],
    ], 290],
]);

/**
 * promtool's output without the long expressions.
 */
function round4StopOutput(string $output): string
{
    return implode("\n", array_map(
        static fn (string $line): string => preg_match('/^\s*expr: /', $line) === 1 ? substr($line, 0, 70).'...' : $line,
        explode("\n", $output),
    ));
}
