<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Owner decision 13, T13 QA round 3 finding 1: every table, caption and per-step graph chooses the start of the period
 * per series (the labels of the replica dedupe: job, connection, queue, job_class, status), then sums by (queue,
 * job_class, status). The start of a sum by (queue, job_class, status) existed as soon as one of its series had one,
 * so a series that appears inside the range next to another job's series of the same queue, class and status added its
 * whole first value (its history), and a counter reset of one series counted every series' whole value.
 *
 * Every affected panel is evaluated exactly as rendered, with the variables as Grafana sends them (Promtool), on two
 * applications a and b with the class App\Jobs\Import on the queue default; b's scrape job is added at 10m with its
 * history (1000 completed, 50 failed). Samples every minute, the range (5m, 20m]: a completes 10 jobs a minute and
 * fails none, b completes 10 and fails 2 a minute: 150 + 100 completed, 20 failed. Before the fix the tables counted
 * 1250 completed and 70 failed: b's whole values, its history included.
 *
 * Grafana's range queries (the tiles #8 A and #4 A, the graph #14) are emulated by a subquery at the graph step (1
 * minute), the points summed: the first point is at 5m, the last at 20m.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * The input series of the two applications, every series of job $job also exported by $replicas instances (replicas
 * behind one scrape job count once, Arch Q1.5); $lateReplica adds a second instance of a from 10m (a replica added
 * inside the range). $class is b's class, $connection b's connection.
 *
 * @return list<array{series: string, values: string}>
 */
function perSeriesInput(string $class = 'Import', string $bJob = 'b', string $connection = 'redis', int $replicas = 1, bool $lateReplica = false): array
{
    $series = static fn (string $job, string $instance, string $connection, string $class, string $status): string => sprintf(
        'ho_queue_jobs_total{job="%s", instance="%s", connection="%s", queue="default", job_class="App\\\\Jobs\\\\%s", status="%s"}',
        $job,
        $instance,
        $connection,
        $class,
        $status,
    );
    $input = [];

    foreach (array_unique(['a', $bJob]) as $job) {
        $input[] = ['series' => 'ho_horizon_status{job="'.$job.'", instance="'.$job.':8080"}', 'values' => $job === 'a' ? '1x20' : '_x10 1x10'];
        $input[] = ['series' => 'ho_queue_pending_jobs{job="'.$job.'", instance="'.$job.':8080", connection="redis", queue="default"}', 'values' => $job === 'a' ? '0x20' : '_x10 0x10'];
    }

    // Batches of the kind Import run the class Import (the Batches table #17)
    $input[] = ['series' => 'ho_queue_batch_created_timestamp_seconds{job="a", instance="a:8080", batch="Import", name="Import 1"}', 'values' => '60x20'];
    $input[] = ['series' => 'ho_queue_batch_job_classes{job="a", instance="a:8080", batch="Import", queue="default", job_class="App\\\\Jobs\\\\Import"}', 'values' => '1x20'];
    $input[] = ['series' => 'ho_queue_batch_job_classes{job="a", instance="a:8080", batch="Import", queue="default", job_class="App\\\\Jobs\\\\'.$class.'"}', 'values' => '1x20'];

    for ($replica = 0; $replica < $replicas; $replica++) {
        $instance = static fn (string $job): string => $job.':'.(8080 + $replica);
        $input[] = ['series' => $series('a', $instance('a'), 'redis', 'Import', 'completed'), 'values' => '100+10x20'];
        $input[] = ['series' => $series('a', $instance('a'), 'redis', 'Import', 'failed'), 'values' => '7x20'];
        $input[] = ['series' => $series($bJob, $instance($bJob), $connection, $class, 'completed'), 'values' => '_x10 1000+10x10'];
        $input[] = ['series' => $series($bJob, $instance($bJob), $connection, $class, 'failed'), 'values' => '_x10 50+2x10'];
    }

    if ($lateReplica) {
        $input[] = ['series' => $series('a', 'a:9090', 'redis', 'Import', 'completed'), 'values' => '_x10 200+10x10'];
        $input[] = ['series' => $series('a', 'a:9090', 'redis', 'Import', 'failed'), 'values' => '_x10 7x10'];
    }

    return array_values(array_unique($input, SORT_REGULAR));
}

/**
 * Panel $id $refId of a render as Grafana sends it at 20m with a 15-minute range; $classes, when given, are the
 * classes of the Job filter: Grafana sends their namespace-stripped display texts as `${job_class:text}` ("A + B") and
 * the classes, regex-escaped (prometheusSpecialRegexEscape: a backslash becomes four in the PromQL text), as
 * `$job_class`.
 *
 * @param  list<string>  $classes
 */
function perSeriesExpr(int $id, string $refId, LiveMode $mode = LiveMode::Poll, array $classes = []): string
{
    $expr = Rendered::expressions(mode: $mode)['panel '.$id.' '.$refId];

    if ($classes !== []) {
        $values = array_map(static fn (string $class): string => 'App\\\\\\\\Jobs\\\\\\\\'.$class, $classes);
        $expr = strtr($expr, [
            '${job_class:text}' => implode(' + ', $classes),
            '$job_class' => count($values) === 1 ? $values[0] : '('.implode('|', $values).')',
        ]);
    }

    return Promtool::interpolate($expr, '.+', 20 * 60, 15 * 60);
}

/**
 * A range query's points summed: the subquery points 5m to 20m at the 1-minute step.
 */
function perSeriesPoints(string $expr): string
{
    return 'sum(sum_over_time(('.$expr.')[16m:1m]))';
}

/**
 * @param  list<array{series: string, values: string}>  $input
 * @param  array<string, list<array{labels: string, value: float|int}>>  $expected  expression => samples at 20m
 * @return array{0: int, 1: string}
 */
function perSeriesRun(array $input, array $expected): array
{
    $tests = [];

    foreach ($expected as $expr => $samples) {
        $tests[] = ['expr' => $expr, 'eval_time' => '20m', 'exp_samples' => $samples];
    }

    return Promtool::testRules([], [['interval' => '1m', 'input_series' => $input, 'promql_expr_test' => $tests]]);
}

/**
 * Every panel that counts the attempts of the period or of the RPM window, with its value for 250 completed and 20
 * failed attempts in (5m, 20m], 100 completed in the RPM window (15m, 20m]; the Job filter of the live renders' RPM
 * caption selects $classes.
 *
 * @param  list<string>  $classes
 * @return array<string, list<array{labels: string, value: float|int}>>
 */
function perSeriesPanels(array $classes): array
{
    $one = static fn (float|int $value, string $labels = '{}'): array => [['labels' => $labels, 'value' => $value]];
    $byStatus = [
        ['labels' => '{status="completed"}', 'value' => 250],
        ['labels' => '{status="failed"}', 'value' => 20],
    ];

    return [
        // The queue tables (#5 read the queue source #24 C) and the Jobs table #6 B (attempts by status)
        'sum by (status) ('.perSeriesExpr(PanelRegistry::QUEUE_SOURCE, 'C').')' => [...$byStatus, ['labels' => '{status="retried"}', 'value' => 0], ['labels' => '{status="timed_out"}', 'value' => 0]],
        'sum by (status) ('.perSeriesExpr(PanelRegistry::JOBS, 'B').') > 0' => $byStatus,
        // The Failed tile: the Success caption floor(1000 * 250 / 270) / 10 and the threshold base (finished attempts)
        perSeriesExpr(PanelRegistry::FAILED, 'B') => $one(92.5),
        perSeriesExpr(PanelRegistry::FAILED, 'C') => $one(270),
        // The RPM caption of the Completed tile: --live=off, and the live renders under a Job filter: round(100 / 5)
        perSeriesExpr(PanelRegistry::COMPLETED, 'B', LiveMode::Off) => $one(20),
        'sum('.perSeriesExpr(PanelRegistry::COMPLETED, 'B', LiveMode::Poll, $classes).')' => $one(20),
        'sum('.perSeriesExpr(PanelRegistry::COMPLETED, 'B', LiveMode::Stream, $classes).')' => $one(20),
        // The Batches table #17: failed, completed and RPM of the kind Import
        perSeriesExpr(PanelRegistry::BATCH_WORKLOAD, 'D') => $one(20, '{batch="Import"}'),
        perSeriesExpr(PanelRegistry::BATCH_WORKLOAD, 'G') => $one(250, '{batch="Import"}'),
        perSeriesExpr(PanelRegistry::BATCH_WORKLOAD, 'H') => $one(20, '{batch="Import"}'),
        // The per-step graph #14 (completed per minute at 1-minute steps), per class and the Total line
        perSeriesPoints(perSeriesExpr(PanelRegistry::COMPLETED_BY_JOB, 'A')) => $one(250),
        perSeriesPoints(perSeriesExpr(PanelRegistry::COMPLETED_BY_JOB, 'B')) => $one(250),
        // The tiles, already per series since QA round 2: the same numbers
        perSeriesPoints(perSeriesExpr(PanelRegistry::COMPLETED, 'A')) => $one(250),
        perSeriesPoints(perSeriesExpr(PanelRegistry::FAILED, 'A')) => $one(20),
    ];
}

it('counts a series that appears inside the range from its first value in every table, caption and graph', function (array $input, array $classes): void {
    [$status, $output] = perSeriesRun($input, perSeriesPanels($classes));

    expect($status)->toBe(0, substr($output, 0, 4000));
})->with([
    // The finding: the same class, queue and status in another scrape job (the same application moved to a dedicated
    // scrape job, or two applications with one class name)
    'another job, the same class' => [perSeriesInput(), ['Import']],
    // The control that passed before: another class
    'another job, another class' => [perSeriesInput(class: 'Export'), ['Import', 'Export']],
    // One application whose second Redis connection appears inside the range
    'the same job, another connection' => [perSeriesInput(bJob: 'a', connection: 'redis-2'), ['Import']],
    // Two replicas behind each scrape job (the dedupe keeps one), and a replica of a added at 10m: no new series
    'two replicas each and a replica added inside the range' => [perSeriesInput(replicas: 2, lateReplica: true), ['Import']],
]);

it('counts only the reset series\' value after a counter reset of one application', function (): void {
    // The verifier's case: a and b on one class, scraped every minute; a's Redis is flushed at 10m (100..109, then
    // 1..11), b counts 5000 + 1 a minute. (5m, 20m]: the tables count a's value after the reset, 11 (the reset rule
    // of AttemptsDeltaTest for one series), and b's 15 attempts: 26 (before the fix the sum of both, 5031). The tiles
    // and the per-step graph count every step but the one of the reset (a: 4 + 10), as TileSparklineTest: 29.
    $series = static fn (string $job): string => sprintf(
        'ho_queue_jobs_total{job="%s", instance="%s:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\Import", status="completed"}',
        $job,
        $job,
    );
    $input = [
        ['series' => 'ho_horizon_status{job="a", instance="a:8080"}', 'values' => '1x20'],
        ['series' => 'ho_horizon_status{job="b", instance="b:8080"}', 'values' => '1x20'],
        ['series' => 'ho_queue_pending_jobs{job="a", instance="a:8080", connection="redis", queue="default"}', 'values' => '0x20'],
        ['series' => 'ho_queue_batch_created_timestamp_seconds{job="a", instance="a:8080", batch="Import", name="Import 1"}', 'values' => '60x20'],
        ['series' => 'ho_queue_batch_job_classes{job="a", instance="a:8080", batch="Import", queue="default", job_class="App\\\\Jobs\\\\Import"}', 'values' => '1x20'],
        ['series' => $series('a'), 'values' => '100+1x9 1+1x10'],
        ['series' => $series('b'), 'values' => '5000+1x20'],
    ];
    $one = static fn (int $value, string $labels = '{}'): array => [['labels' => $labels, 'value' => $value]];

    [$status, $output] = perSeriesRun($input, [
        'sum(('.perSeriesExpr(PanelRegistry::QUEUE_SOURCE, 'C').') and on (status) label_replace(vector(1), "status", "completed", "", ""))' => $one(26),
        'sum(('.perSeriesExpr(PanelRegistry::JOBS, 'B').') and on (status) label_replace(vector(1), "status", "completed", "", ""))' => $one(26),
        perSeriesExpr(PanelRegistry::BATCH_WORKLOAD, 'G') => $one(26, '{batch="Import"}'),
        // (15m, 20m]: a 5 and b 5, round(10 / 5)
        perSeriesExpr(PanelRegistry::COMPLETED, 'B', LiveMode::Off) => $one(2),
        perSeriesPoints(perSeriesExpr(PanelRegistry::COMPLETED, 'A')) => $one(29),
        // The legacy per-step form compared each step with the value at the period start, 105, so after a's reset
        // every step counted a's whole value (136)
        perSeriesPoints(perSeriesExpr(PanelRegistry::COMPLETED_BY_JOB, 'B')) => $one(29),
        perSeriesPoints(perSeriesExpr(PanelRegistry::COMPLETED_BY_JOB, 'A')) => $one(29),
    ]);

    expect($status)->toBe(0, substr($output, 0, 4000));
});
