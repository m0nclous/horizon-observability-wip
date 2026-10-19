<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Live\Captions;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The --live=off Supervisor Processes tile (#3), the queue source of the queue tables (#24) and the variables that list
 * the supervisors' queues, evaluated by promtool as Grafana sends them.
 *
 * - A balance=false supervisor has one process pool for all its queues, and horizon_supervisor_processes labels it with
 *   the comma-joined queue list (`mail,sms`), which matches neither a queue name nor the Queue filter: under Queue =
 *   All Grafana expands the filter to the listed queues, `(default|mail|sms)`. horizon_supervisor_queues maps each
 *   queue to its pool, so the tile counts the pool, each of its queues has a row with the pool's processes and the
 *   pool's utilisation, and the Queue filter and the table titles know the supervisor (#32).
 * - Under a Job filter the tile counts the workers busy with the selected classes. queue_jobs_reserved exists only for
 *   classes with reserved jobs, so a supervisor without such workers had no value and the tile showed a dash; it shows
 *   0 now for every supervisor in the selection, and a dash only without any data (#34).
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

const POOL_LABELS = 'job="app", instance="app:8080"';

/**
 * An expression as Grafana sends it at 10m with a 10-minute range: Queue All (the listed queues) unless $queue is
 * given, Job All unless $class is given, every other filter All.
 */
function poolExpr(string $expr, ?string $queue = null, ?string $class = null): string
{
    $replace = [
        '${queue:text}' => $queue ?? 'All',
        '$queue' => $queue ?? '(default|mail|sms)',
    ];

    if ($class !== null) {
        $replace += ['${job_class:text}' => $class, '$job_class' => $class];
    }

    // query_result() is Grafana's, around a variable query
    $expr = (string) preg_replace('/^query_result\((.*)\)$/s', '$1', strtr($expr, $replace));

    return Promtool::interpolate($expr, '.+', 600, 600);
}

/**
 * An expression of the off render: `panel <id> <refId>` or `variable <name>`.
 */
function poolRendered(string $where): string
{
    return Rendered::expressions(mode: LiveMode::Off)[$where];
}

/**
 * Two supervisors at 30-second scrapes for 10 minutes: `a` balances default (3 of max 10 processes), `pool` runs
 * balance=false on mail and sms (one pool `mail,sms`, 2 of max 4 processes), with one reserved job of $busyClass on
 * mail and one of Other on sms.
 *
 * @return list<array{series: string, values: string}>
 */
function poolSeries(string $busyClass = 'SendEmail'): array
{
    $labels = POOL_LABELS;
    $series = [
        ['ho_horizon_status{'.$labels.'}', '1x20'],
        ['ho_horizon_supervisor_processes{'.$labels.', supervisor="supervisor-a", queue="default"}', '3x20'],
        ['ho_horizon_supervisor_processes{'.$labels.', supervisor="supervisor-pool", queue="mail,sms"}', '2x20'],
        ['ho_horizon_supervisor_max_processes{'.$labels.', supervisor="supervisor-a"}', '10x20'],
        ['ho_horizon_supervisor_max_processes{'.$labels.', supervisor="supervisor-pool"}', '4x20'],
        ['ho_horizon_supervisor_queues{'.$labels.', supervisor="supervisor-a", queue="default", pool="default"}', '1x20'],
        ['ho_horizon_supervisor_queues{'.$labels.', supervisor="supervisor-pool", queue="mail", pool="mail,sms"}', '1x20'],
        ['ho_horizon_supervisor_queues{'.$labels.', supervisor="supervisor-pool", queue="sms", pool="mail,sms"}', '1x20'],
        ['ho_queue_jobs_reserved{'.$labels.', connection="redis", queue="mail", job_class="'.$busyClass.'"}', '1x20'],
        ['ho_queue_jobs_reserved{'.$labels.', connection="redis", queue="sms", job_class="Other"}', '1x20'],
    ];

    foreach (['default', 'mail', 'sms'] as $queue) {
        $series[] = ['ho_queue_pending_jobs{'.$labels.', connection="redis", queue="'.$queue.'"}', '0x20'];
        $series[] = ['ho_queue_delayed_jobs{'.$labels.', connection="redis", queue="'.$queue.'"}', '0x20'];
        $series[] = ['ho_queue_delayed_due_jobs{'.$labels.', connection="redis", queue="'.$queue.'"}', '0x20'];
    }

    return array_map(static fn (array $row): array => ['series' => $row[0], 'values' => $row[1]], $series);
}

/**
 * promtool's verdict on expression tests at 10m over poolSeries().
 *
 * @param  list<array{expr: string, exp_samples: list<array{labels: string, value: int|float}>}>  $tests
 * @param  list<array{series: string, values: string}>|null  $series
 * @return array{0: int, 1: string}
 */
function poolEvaluate(array $tests, ?array $series = null): array
{
    return Promtool::testRules([], [[
        'interval' => '30s',
        'input_series' => $series ?? poolSeries(),
        'promql_expr_test' => array_map(static fn (array $test): array => [...$test, 'eval_time' => '10m'], $tests),
    ]]);
}

it('counts a balance=false pool in Supervisor Processes under Queue All and under a Queue filter on one of its queues (#32)', function (): void {
    $tile = poolRendered('panel '.PanelRegistry::SUPERVISOR_PROCESSES.' A');

    [$status, $output] = poolEvaluate([
        // Queue All: both supervisors, the pool at 50 % (two reserved jobs of 4), so the values are exact
        ['expr' => poolExpr($tile), 'exp_samples' => [
            ['labels' => '{supervisor="supervisor-a", max="10", name="a"}', 'value' => 3],
            ['labels' => '{supervisor="supervisor-pool", max="4", name="pool"}', 'value' => 2],
        ]],
        // Queue = sms: the pool works it, supervisor-a does not
        ['expr' => poolExpr($tile, queue: 'sms'), 'exp_samples' => [
            ['labels' => '{supervisor="supervisor-pool", max="4", name="pool"}', 'value' => 2],
        ]],
        // A Job filter: the pool's workers busy with the class in its queues
        ['expr' => poolExpr($tile, class: 'SendEmail'), 'exp_samples' => [
            ['labels' => '{supervisor="supervisor-a", max="10", name="a"}', 'value' => 0],
            ['labels' => '{supervisor="supervisor-pool", max="4", name="pool"}', 'value' => 1],
        ]],
    ]);

    expect($status)->toBe(0, $output);
});

it('gives each queue of a balance=false pool a row with the pool processes and utilisation in the queue tables (#32)', function (): void {
    $source = static fn (string $refId): string => poolRendered('panel '.PanelRegistry::QUEUE_SOURCE.' '.$refId);
    $percent = static fn (int $percent): int => Captions::PERCENT_OFFSET + $percent;

    [$status, $output] = poolEvaluate([
        // The rows: one per (queue, supervisor)
        ['expr' => poolExpr($source('M')), 'exp_samples' => [
            ['labels' => '{queue="default", supervisor="supervisor-a"}', 'value' => 1],
            ['labels' => '{queue="mail", supervisor="supervisor-pool"}', 'value' => 1],
            ['labels' => '{queue="sms", supervisor="supervisor-pool"}', 'value' => 1],
        ]],
        // Processes: the pool's for each of its queues, as the live snapshot's workload
        ['expr' => poolExpr($source('D')), 'exp_samples' => [
            ['labels' => '{queue="default"}', 'value' => 3],
            ['labels' => '{queue="mail"}', 'value' => 2],
            ['labels' => '{queue="sms"}', 'value' => 2],
        ]],
        // Utilisation: the pool's busy workers of both queues over its maxProcesses, 2 of 4
        ['expr' => poolExpr($source('A')), 'exp_samples' => [
            ['labels' => '{queue="default"}', 'value' => $percent(0)],
            ['labels' => '{queue="mail"}', 'value' => $percent(50)],
            ['labels' => '{queue="sms"}', 'value' => $percent(50)],
        ]],
        // A Queue filter on a pool queue keeps that row only
        ['expr' => poolExpr($source('M'), queue: 'mail'), 'exp_samples' => [
            ['labels' => '{queue="mail", supervisor="supervisor-pool"}', 'value' => 1],
        ]],
    ]);

    expect($status)->toBe(0, $output);
});

it('lists the queues of a balance=false supervisor in the Queue filter and titles its queue table (#32)', function (): void {
    $queues = poolRendered('variable queue');
    $titles = poolRendered('variable supervisor_title');

    [$status, $output] = poolEvaluate([
        // Supervisor = supervisor-pool: its queues, not its pool label
        ['expr' => poolExpr(str_replace('$supervisor', 'supervisor-pool', $queues)), 'exp_samples' => [
            ['labels' => '{queue="mail"}', 'value' => 1],
            ['labels' => '{queue="sms"}', 'value' => 1],
        ]],
        // Queue All: a table for each supervisor (no supervisor_info here: the titles are the names)
        ['expr' => poolExpr($titles), 'exp_samples' => [
            ['labels' => '{supervisor="supervisor-a", display="supervisor-a"}', 'value' => 1],
            ['labels' => '{supervisor="supervisor-pool", display="supervisor-pool"}', 'value' => 1],
        ]],
    ]);

    expect($status)->toBe(0, $output);
});

it('shows 0, not a dash, for every supervisor without workers busy with the selected class (#34)', function (): void {
    $tile = poolRendered('panel '.PanelRegistry::SUPERVISOR_PROCESSES.' A');

    // Another class is busy on mail and sms; SendEmail has no reserved jobs anywhere
    [$status, $output] = poolEvaluate([['expr' => poolExpr($tile, class: 'SendEmail'), 'exp_samples' => [
        ['labels' => '{supervisor="supervisor-a", max="10", name="a"}', 'value' => 0],
        ['labels' => '{supervisor="supervisor-pool", max="4", name="pool"}', 'value' => 0],
    ]]], poolSeries(busyClass: 'Import'));

    expect($status)->toBe(0, $output);
});

it('keeps the dash of Supervisor Processes under a Job filter without any supervisor (#34)', function (): void {
    $tile = poolRendered('panel '.PanelRegistry::SUPERVISOR_PROCESSES.' A');
    $series = array_values(array_filter(poolSeries(), static fn (array $row): bool => ! str_contains($row['series'], 'supervisor')));

    [$status, $output] = poolEvaluate([['expr' => poolExpr($tile, class: 'SendEmail'), 'exp_samples' => []]], $series);

    expect($status)->toBe(0, $output);
});
