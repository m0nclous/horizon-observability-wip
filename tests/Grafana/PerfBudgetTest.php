<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Perf\StaticBudget;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Workbench\App\Perf\SyntheticModel;

/*
 * The static performance gates of the committed render, computed from the JSON with every filter at "All": no
 * Prometheus needed, so the CI grafana job runs them on every change. The wall-time budget is measured with
 * bin/perf.mjs on the reference profile (docker/perf/README.md). Every committed render (off, poll, stream) meets them;
 * the live modes add at most 5 snapshot calls per refresh.
 */

pest()->group('grafana');

/**
 * The options of the multi-value filters without an allValue on the reference profile (medium):
 * Grafana's "All" of those is the regex of every option.
 *
 * @return array<string, list<string>>
 */
function referenceOptions(): array
{
    $options = ['queue' => [], 'job_class' => []];

    foreach ((new SyntheticModel('medium'))->series(1_792_256_400) as [$name, $labels]) {
        if ($name === 'queue_pending_jobs' && preg_match('/queue="([^"]+)"/', $labels, $queue) === 1) {
            $options['queue'][$queue[1]] = true;
        }

        if (preg_match('/job_class="((?:[^"\\\\]|\\\\.)+)"/', $labels, $class) === 1) {
            $options['job_class'][stripcslashes($class[1])] = true;
        }
    }

    return array_map(array_keys(...), $options);
}

function committedBudget(LiveMode $mode = LiveMode::Poll): StaticBudget
{
    return new StaticBudget(Rendered::dashboard($mode), referenceOptions());
}

/**
 * A minimal dashboard for the gate tests.
 *
 * @param  list<array<string, mixed>>  $panels
 * @param  list<array<string, mixed>>  $variables
 * @return array<string, mixed>
 */
function budgetDashboard(array $panels, array $variables = []): array
{
    return ['panels' => $panels, 'templating' => ['list' => $variables]];
}

/**
 * @param  list<string>  $exprs
 * @return array<string, mixed>
 */
function budgetPanel(int $id, array $exprs, string $datasource = '${datasource}', array $extra = []): array
{
    return [
        'datasource' => ['type' => 'prometheus', 'uid' => $datasource],
        'id' => $id,
        'targets' => array_map(static fn (string $expr, int $i): array => ['expr' => $expr, 'refId' => chr(65 + $i)], $exprs, array_keys($exprs)),
        'type' => 'stat',
        ...$extra,
    ];
}

it('looks back at most max(10m, 10 x S) from the start of a period on every counter', function (): void {
    expect(committedBudget()->counterLookbacks())->toBe([]);
});

it('evaluates no operand twice for the Batch switch: the scope sits in the selectors', function (): void {
    expect(committedBudget()->duplicateBranches())->toBe([]);
});

it('refreshes only the batch variables with the time range', function (): void {
    $budget = committedBudget();

    expect($budget->variablePolicy())->toBe([])
        ->and($budget->variableRequests())->toBe(2)
        ->and($budget->variableRequests())->toBeLessThanOrEqual(StaticBudget::MAX_VARIABLE_REQUESTS);
});

it('sends at most 12 requests per refresh: the queue tables read the hidden source #24', function (LiveMode $mode, int $requests, int $live): void {
    $budget = committedBudget($mode);
    $source = array_values(array_filter(Rendered::dashboard($mode)['panels'], static fn (array $panel): bool => $panel['id'] === StaticBudget::QUEUE_SOURCE));

    expect($budget->requests())->toBe($requests)
        ->and($budget->requests())->toBeLessThanOrEqual(StaticBudget::MAX_REQUESTS)
        // The live tiles (poll #1 L, #3 S, #2 L and C, #8 C and #9 L; stream only the #1 status and the #8 caption)
        // and the queue source's columns of now (#24 W) in both live modes
        ->and($budget->liveRequests())->toBe($live)
        ->and($budget->liveRequests())->toBeLessThanOrEqual(StaticBudget::MAX_LIVE_REQUESTS)
        ->and($budget->queueSource())->toBe([])
        ->and($source[0]['maxDataPoints'] ?? null)->toBe(100)
        ->and($budget->panelBytes()[5])->toBe(0);
})->with([
    'poll (package render)' => [LiveMode::Poll, 11, 7],
    'off' => [LiveMode::Off, 12, 0],
    'stream' => [LiveMode::Stream, 9, 3],
]);

it('meets every static gate of the budget with one attempts query per table', function (LiveMode $mode): void {
    $budget = committedBudget($mode);

    expect($budget->violations())->toBe([])
        ->and($budget->interpolatedBytes())->toBeLessThanOrEqual(StaticBudget::MAX_INTERPOLATED_BYTES)
        ->and(max($budget->panelBytes()))->toBeLessThanOrEqual(StaticBudget::MAX_PANEL_BYTES);
})->with(Rendered::modes());

it('counts the live snapshot calls apart and Grafana Live subscriptions not at all', function (): void {
    $live = static fn (string $refId): array => [
        'refId' => $refId,
        'datasource' => ['type' => 'yesoreyeram-infinity-datasource', 'uid' => '${app_api}'],
        'url' => '${api_path}/live?${supervisor:queryparam}',
    ];
    $budget = new StaticBudget(budgetDashboard([
        // Mixed: Prometheus A and two snapshot calls
        budgetPanel(1, ['sum(x)'], '-- Mixed --', ['targets' => [['refId' => 'A', 'expr' => 'sum(x)', 'datasource' => ['type' => 'prometheus', 'uid' => '${datasource}']], $live('L'), $live('C')]]),
        // Only the snapshot
        budgetPanel(2, [], '${app_api}', ['targets' => [$live('S')]]),
        // A job list is a request of its own
        budgetPanel(3, [], '${app_api}', ['targets' => [['refId' => 'A', 'datasource' => ['type' => 'yesoreyeram-infinity-datasource', 'uid' => '${app_api}'], 'url' => '${api_path}/jobs/failed?limit=500']]]),
        // A Grafana Live channel
        budgetPanel(4, [], 'grafana', ['targets' => [['refId' => 'A', 'datasource' => ['type' => 'datasource', 'uid' => 'grafana'], 'channel' => 'stream/x/tiles']]]),
        // A repeated copy calls the snapshot once per copy
        budgetPanel(5, [], '${app_api}', ['targets' => [$live('S')], 'repeat' => 'supervisor_title']),
        // One call more than the budget
        budgetPanel(6, [], '${app_api}', ['targets' => [$live('S'), $live('T')]]),
    ]), repeats: 3);

    expect($budget->requests())->toBe(1 + 1)
        ->and($budget->liveRequests())->toBe(2 + 1 + 3 + 2)
        ->and($budget->violations())->toBe(['8 live snapshot calls per refresh, more than 7']);
});

it('finds a day-long counter lookback, also in collapsed rows and variables', function (): void {
    $budget = new StaticBudget(budgetDashboard([
        budgetPanel(1, ['sum(last_over_time(ho_queue_jobs_total{job=~".+"}[1d] offset 1h))']),
        ['type' => 'row', 'id' => 2, 'collapsed' => true, 'panels' => [
            budgetPanel(3, ['increase(ho_queue_job_duration_seconds_bucket[7d])', 'max_over_time(ho_queue_jobs_pending[1d])']),
        ]],
    ], [
        ['type' => 'query', 'name' => 'x', 'refresh' => 1, 'query' => 'query_result(last_over_time(office_queue_jobs_total[2h]))'],
    ]));

    expect($budget->counterLookbacks())->toBe([
        'panel 1 A: a counter looks back 1d',
        'panel 3 A: a counter looks back 7d',
        'variable x: a counter looks back 2h',
    ]);
});

it('counts requests and interpolated bytes per refresh as Grafana sends them', function (): void {
    $budget = new StaticBudget(budgetDashboard([
        budgetPanel(1, ['sum(x{queue=~"$queue", job_class=~"$job_class"})', 'y[$__range] @ ${__to:date:seconds}']),
        // Repeated: each copy is a request with its own value of the repeat variable
        budgetPanel(2, ['z{supervisor=~"$supervisor_title"}'], extra: ['repeat' => 'supervisor_title']),
        // Reads panel 1: no request
        budgetPanel(3, [], '-- Dashboard --'),
        ['type' => 'row', 'id' => 4, 'collapsed' => true, 'panels' => [budgetPanel(5, ['collapsed'])]],
    ], [
        ['type' => 'query', 'name' => 'queue', 'refresh' => 1, 'includeAll' => true, 'multi' => true, 'query' => 'q'],
        ['type' => 'query', 'name' => 'job_class', 'refresh' => 1, 'allValue' => '.+', 'query' => 'j'],
        ['type' => 'query', 'name' => 'supervisor_title', 'refresh' => 2, 'allValue' => '.*', 'query' => 'query_result(s[$__range])'],
    ]), ['queue' => ['a.b', 'c']], repeats: 3);

    expect($budget->requests())->toBe(1 + 3 + 1)
        ->and($budget->variableRequests())->toBe(1)
        ->and($budget->interpolate('sum(x{queue=~"$queue", job_class=~"$job_class"}) ${queue:text} $__interval_ms'))
        ->toBe('sum(x{queue=~"(a\\\\.b|c)", job_class=~".+"}) All 10000')
        ->and($budget->panelBytes())->toBe([
            1 => strlen('sum(x{queue=~"(a\\\\.b|c)", job_class=~".+"})') + strlen('y[3600s] @ 1792256400'),
            2 => 3 * strlen('z{supervisor=~"supervisor-notifications"}'),
            3 => 0,
        ])
        ->and($budget->interpolatedBytes())->toBe(array_sum($budget->panelBytes()) + strlen('query_result(s[3600s])'));
});

it('finds variables outside the refresh policy', function (): void {
    $budget = new StaticBudget(budgetDashboard([], [
        ['type' => 'query', 'name' => 'queue', 'refresh' => 2, 'query' => 'q'],
        ['type' => 'query', 'name' => 'batch_kind', 'refresh' => 1, 'query' => 'b'],
        ['type' => 'query', 'name' => 'batch_title', 'refresh' => 2, 'query' => 't'],
        ['type' => 'datasource', 'name' => 'datasource', 'refresh' => 1, 'query' => 'prometheus'],
        ['type' => 'textbox', 'name' => 'busy_window', 'query' => '20s'],
    ]));

    expect($budget->variablePolicy())->toBe([
        'variable queue: refresh 2 instead of 1',
        'variable batch_kind: refresh 1 instead of 2',
    ]);
});

it('finds an operand that the Batch switch evaluates twice (the legacy batch_filtered())', function (): void {
    $all = '(label_replace(vector(1), "filter", `${batch_kind:text}`, "", "") and on (filter) label_replace(vector(1), "filter", "All", "", ""))';
    $rows = '(group by (queue) (ho_queue_pending_jobs))';
    $classes = 'group by (queue) (ho_queue_batch_job_classes{batch=~"$batch_kind"})';
    $jobs = '(label_replace(vector(1), "filter", `${job_class:text}`, "", "") and on (filter) label_replace(vector(1), "filter", "All", "", ""))';

    $budget = new StaticBudget(budgetDashboard([
        budgetPanel(1, [$rows.' and on (queue) '.$classes.' or '.$rows.' and on () '.$all]),
        // A chain of filter tests is no branch
        budgetPanel(2, ['x and on () ('.$jobs.' and on () '.$all.') or y unless on () ('.$jobs.' and on () '.$all.')']),
        budgetPanel(3, [$rows.' unless on (queue) (group by (queue) ({__name__="ho_queue_pending_jobs", __batch_scope__!~"$batch_kind"}))']),
    ]));

    expect($budget->duplicateBranches())->toHaveCount(1)
        ->and($budget->duplicateBranches()[0])->toStartWith('panel 1 A: ');
});

it('requires maxDataPoints on the hidden queue source', function (): void {
    $source = static fn (array $extra): StaticBudget => new StaticBudget(budgetDashboard([budgetPanel(StaticBudget::QUEUE_SOURCE, ['x'], extra: $extra)]));

    expect($source([])->queueSource())->toBe(['panel 24: the hidden queue source has no maxDataPoints'])
        ->and($source(['maxDataPoints' => 100])->queueSource())->toBe([]);
});
