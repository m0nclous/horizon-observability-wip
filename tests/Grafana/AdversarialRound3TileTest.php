<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * T13 QA round 3 (adversarial), owner decision 13: the round 2 finding 1 scenario (the scrape job of an application
 * added inside the range, with the default prometheus_job "All") when the new job exports a (queue, job_class, status)
 * that another job already exports: the same application moved to the dedicated scrape job that ADDENDUM R3.2
 * recommends, or two applications with a class App\Jobs\X on the queue default. In the dev stack (Grafana 13.2.4) a
 * scrape job added at 06:52:50 next to three jobs scraping the same workbench gave, for 06:48-06:58 with All jobs,
 * 16,017 completed and 101 failed in the queue tables while the tiles and the truth (raw samples) had 13,223 and 79.
 *
 * 1. Every table and caption that counts attempts with Attempts::delta() (#24 C behind the queue tables #5, #6 B, the
 *    Success caption #4 B and the threshold base #4 C, the RPM caption #8 B, the Batches columns #17 D/G/H) and the
 *    per-step graphs #14 (Attempts::perStep()) sum the series by (queue, job_class, status) before they choose the
 *    start of each sum: the group has a start (the old job's), so the new job's series never takes the fallback "first
 *    value in the range" and its whole first value, its history, is counted. The round 2 fix moved the tiles to a
 *    per-series form; the tables keep the bug. The per-series start (by job and connection) is what T16's
 *    never-count-first-values rule needs.
 * 2. The tiles (Attempts::perStepTotal()) count such a series from the first subquery point after its first sample,
 *    not from its first value as their docblock says: whenever the graph step is longer than the scrape interval, the
 *    attempts of its first partial step are lost. At 1920 px and 10 s scrapes Grafana sends the Failed tile (226 px)
 *    15 s steps for a 1-hour range and 2 minutes for 6 hours, the Completed tile (304 px) 1 minute for 6 hours. In the
 *    dev stack (Grafana 12.4.13 and 13.2.4 alike) a scrape job added at 07:01:04 gave, for 06:59-07:09, 2,920 completed
 *    in the tables and the truth, and 2,871 on the tile at 15 s steps, 2,640 at 1 minute. T16's new-series hold does
 *    not apply (the series are old). Until T16 lands every new class is such a series (TestBatchReportJob, first seen
 *    within 07:02-07:17: 5,652 on the tile at 1-minute steps against 5,664); with T16 a new class is held at 0 for 90 s,
 *    which the 2-minute step of the Failed tile over 6 hours does not always reach (last test). The existing truth tests
 *    sample at the graph step (1m series, 1m steps), where this loss is 0.
 *
 * Grafana's range query (step = $__interval, reduced with sum) is emulated by a subquery at the same step, as in
 * CounterBirthTest. The samples are 10 s apart (the recommended scrape interval); the graph step is 1 minute, 2 in the
 * last test.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * Rendered expression $refId of panel $id as Grafana sends it at $to (minutes) with a 15-minute range, every filter All.
 */
function round3Expr(int $id, string $refId, int $to = 19, LiveMode $mode = LiveMode::Poll): string
{
    return Promtool::interpolate(Rendered::expressions(Rendered::dashboard($mode))['panel '.$id.' '.$refId], '.+', $to * 60, 15 * 60);
}

/**
 * The input series: applications a and b, one queue and one class; the scrape job of b is added at 10m10s, when b's
 * counters already hold its history (1000 completed, 50 failed). Samples every 10 s up to 19m. a completes 1 job per
 * scrape throughout and fails none; b completes 1 job per scrape and fails 1 job at every whole minute from 11m on.
 * In the range (4m, 19m]: a 90 completed; b 53 completed and 8 failed.
 *
 * @return list<array{series: string, values: string}>
 */
function round3Series(): array
{
    $series = static fn (string $job, string $status): string => sprintf(
        'ho_queue_jobs_total{job="%s", instance="%s:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\Import", status="%s"}',
        $job,
        $job,
        $status,
    );

    return [
        ['series' => 'ho_horizon_status{job="a", instance="a:8080"}', 'values' => '1x114'],
        ['series' => 'ho_horizon_status{job="b", instance="b:8080"}', 'values' => '_x61 1x53'],
        ['series' => 'ho_queue_pending_jobs{job="a", instance="a:8080", connection="redis", queue="default"}', 'values' => '0x114'],
        ['series' => 'ho_queue_pending_jobs{job="b", instance="b:8080", connection="redis", queue="default"}', 'values' => '_x61 0x53'],
        ['series' => $series('a', 'completed'), 'values' => '500+1x114'],
        ['series' => $series('a', 'failed'), 'values' => '7x114'],
        ['series' => $series('b', 'completed'), 'values' => '_x61 1000+1x53'],
        // 50 from 10m10s to 10m50s, then +1 at every whole minute from 11m to 18m
        ['series' => $series('b', 'failed'), 'values' => '_x61 50x4 51x5 52x5 53x5 54x5 55x5 56x5 57x5 58x5 58'],
    ];
}

/**
 * @param  list<array<string, mixed>>  $tests
 * @return array{0: int, 1: string}
 */
function round3Run(array $tests): array
{
    return Promtool::testRules([], [['interval' => '10s', 'input_series' => round3Series(), 'promql_expr_test' => $tests]]);
}

it('does not count the history of a new job\'s series in the tables when another job exports the same class', function (): void {
    $completed = 'label_replace(vector(1), "status", "completed", "", "")';

    [$status, $output] = round3Run([
        // The queue tables (#5 read #24 C): 90 + 53 completed, 8 failed (the committed render counts 1143 and 58)
        ['expr' => 'sum(('.round3Expr(PanelRegistry::QUEUE_SOURCE, 'C').') and on (status) '.$completed.')', 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 143]]],
        ['expr' => 'sum(('.round3Expr(PanelRegistry::QUEUE_SOURCE, 'C').') and on (status) label_replace(vector(1), "status", "failed", "", ""))', 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 8]]],
        // The Failed tile's threshold base (finished attempts) and Success caption: 151 attempts, floor(1000 * 143 / 151) / 10
        ['expr' => round3Expr(PanelRegistry::FAILED, 'C'), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 151]]],
        ['expr' => round3Expr(PanelRegistry::FAILED, 'B'), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 94.7]]],
        // The per-class table #6 (attempts by status): 143 completed of App\Jobs\Import
        ['expr' => 'sum(('.round3Expr(PanelRegistry::JOBS, 'B').') and on (status) '.$completed.')', 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 143]]],
        // The RPM caption of the --live=off render 4m after the job was added: (9m, 14m] has a 30 and b 23 completed,
        // round(53 / 5) (the committed render shows 211)
        ['expr' => round3Expr(PanelRegistry::COMPLETED, 'B', 14, LiveMode::Off), 'eval_time' => '14m', 'exp_samples' => [['labels' => '{}', 'value' => 11]]],
        // The per-step graph #14 (Total, attempts per minute at 1-minute steps), its points summed: 143
        ['expr' => 'sum_over_time(('.round3Expr(PanelRegistry::COMPLETED_BY_JOB, 'B').')[16m:1m])', 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 143]]],
    ]);

    expect($status)->toBe(0, $output);
});

it('does not count another application\'s history in the tables after a counter reset of one application', function (): void {
    $series = static fn (string $job): string => sprintf(
        'ho_queue_jobs_total{job="%s", instance="%s:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\Import", status="completed"}',
        $job,
        $job,
    );
    $completed = 'label_replace(vector(1), "status", "completed", "", "")';

    // Two applications with the same class on the queue default, scraped every minute; a's Redis is flushed at 10m
    // (its counter restarts from 0). In (4m, 19m] the tables count a's value after its reset, 10 (the rule of
    // AttemptsDeltaTest for one series), and b's 15 attempts: 25
    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="a", instance="a:8080"}', 'values' => '1x20'],
            ['series' => 'ho_horizon_status{job="b", instance="b:8080"}', 'values' => '1x20'],
            ['series' => 'ho_queue_pending_jobs{job="a", instance="a:8080", connection="redis", queue="default"}', 'values' => '0x20'],
            ['series' => 'ho_queue_pending_jobs{job="b", instance="b:8080", connection="redis", queue="default"}', 'values' => '0x20'],
            ['series' => $series('a'), 'values' => '100+1x9 1+1x10'],
            ['series' => $series('b'), 'values' => '5000+1x20'],
        ],
        'promql_expr_test' => [
            // The queue tables (#24 C) count b's history too, 5,029: the sum of the group fell, so it takes "the value
            // after a reset", the sum of both applications
            ['expr' => 'sum(('.Promtool::interpolate(Rendered::expressions()['panel '.PanelRegistry::QUEUE_SOURCE.' C'], '.+', 19 * 60, 15 * 60).') and on (status) '.$completed.')', 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 25]]],
        ],
    ]]);

    expect($status)->toBe(0, $output);
});

it('counts a new job\'s series from its first value on the tiles at a graph step longer than the scrape interval', function (): void {
    $tile = static fn (int $id): string => 'sum_over_time(('.round3Expr($id, 'A').')[16m:1m])';

    [$status, $output] = round3Run([
        // The tile numbers equal the tables' 143 and 8 (the round 2 form counts 138 and 7: b's first 50 s are lost)
        ['expr' => $tile(PanelRegistry::COMPLETED), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 143]]],
        ['expr' => $tile(PanelRegistry::FAILED), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 8]]],
    ]);

    expect($status)->toBe(0, $output);
});

it('counts a new class held at 0 by the exporter (T16) at the 2-minute step of the Failed tile over 6 hours', function (): void {
    // The Failed tile is 226 px wide: Grafana sends 2-minute steps for a 6-hour range at 1920 px. T16 exports a new
    // series as 0 for its first 90 s; when no step boundary (an even minute) falls inside those 90 s, the tile has no
    // point at 0 and counts the class from its value at the next boundary.
    $series = static fn (string $class, string $status): string => sprintf(
        'ho_queue_jobs_total{job="app", instance="app:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\%s", status="%s"}',
        $class,
        $status,
    );
    $twoMinutes = static fn (string $expr): string => Promtool::interpolate(strtr($expr, [
        '${__interval_ms}' => '120000',
        '$__interval_ms' => '120000',
        '$__interval' => '2m',
    ]), '.+', 20 * 60, 16 * 60);
    $failed = 'label_replace(vector(1), "status", "failed", "", "")';

    // A new class from 10m05s, failing every 10 s: exported as 0 from 10m10s to 11m30s (the hold), then 10 at 11m40s
    // and +1 a scrape: 60 failures in (4m, 20m], all after its creation
    [$status, $output] = Promtool::testRules([], [[
        'interval' => '10s',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x120'],
            ['series' => 'ho_queue_pending_jobs{job="app", instance="app:8080", connection="redis", queue="default"}', 'values' => '0x120'],
            ['series' => $series('Old', 'failed'), 'values' => '3x120'],
            ['series' => $series('New', 'failed'), 'values' => '_x61 0x8 10+1x50'],
        ],
        'promql_expr_test' => [
            ['expr' => 'sum(('.$twoMinutes(Rendered::expressions()['panel '.PanelRegistry::QUEUE_SOURCE.' C']).') and on (status) '.$failed.')', 'eval_time' => '20m', 'exp_samples' => [['labels' => '{}', 'value' => 60]]],
            // The tile counts 48: the 12 failures up to 12m are lost
            ['expr' => 'sum_over_time(('.$twoMinutes(Rendered::expressions()['panel '.PanelRegistry::FAILED.' A']).')[17m:2m])', 'eval_time' => '20m', 'exp_samples' => [['labels' => '{}', 'value' => 60]]],
        ],
    ]]);

    expect($status)->toBe(0, $output);
});
