<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\AlertRulesBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Arch Q5.3 and Q5.7 item 4: the committed alert rules (JSON provisioning file) are the four core rules, with the
 * stall rule <uid>-live as a fifth one in stream mode (ADDENDUM R4.11); their uids start with the dashboard uid and fit
 * Grafana's 40 characters, they link to existing panels, the Alerts tile and the alert sound watch exactly them,
 * nothing Grafana would interpolate contains "$", and the datasource is the environment placeholder.
 */

pest()->group('grafana');

/**
 * @return list<array<string, mixed>>
 */
function committedRules(LiveMode $mode = LiveMode::Poll): array
{
    return Rendered::alerting($mode)['groups'][0]['rules'];
}

it('provisions the core rules of the dashboard as JSON, with the stall rule in stream mode', function (LiveMode $mode): void {
    $group = Rendered::alerting($mode)['groups'][0];
    $live = $mode === LiveMode::Stream;

    expect(array_keys(Rendered::alerting($mode)))->toBe(['apiVersion', 'groups', 'deleteRules'])
        ->and($group)->toMatchArray(['orgId' => 1, 'name' => 'Queues', 'folder' => 'Horizon', 'interval' => '1m'])
        ->and(array_column(committedRules($mode), 'uid'))->toBe([
            'horizon-observability-no-metrics',
            'horizon-observability-down',
            'horizon-observability-long-wait',
            'horizon-observability-failures',
            ...($live ? ['horizon-observability-live'] : []),
        ])
        ->and(array_column(committedRules($mode), 'title'))->toBe([
            'No queue metrics', 'Horizon is down', 'Queue wait over 2x limit', 'High failure rate',
            ...($live ? ['Live overview stalled'] : []),
        ]);
})->with(Rendered::modes());

it('deletes the optional rules a render leaves out, so a switch of mode or of --health-rules leaves none behind', function (LiveMode $mode, bool $health, array $deleted): void {
    // Grafana keeps a provisioned rule that disappears from the file: after a switch from stream to poll the stall rule
    // would fire once the stream command stops; deleting a rule that does not exist is a no-op (probed on 12 and 13)
    $provisioning = (new AlertRulesBuilder(new DashboardOptions(uid: 'queues', live: $mode, healthRules: $health)))->build();
    $rendered = array_column($provisioning['groups'][0]['rules'], 'uid');

    expect($provisioning['deleteRules'] ?? [])->toBe(array_map(static fn (string $uid): array => ['orgId' => 1, 'uid' => $uid], $deleted))
        ->and(array_intersect($rendered, $deleted))->toBe([])
        ->and(count($rendered) + count($deleted))->toBe(4 + 1 + 2);
})->with([
    'poll' => [LiveMode::Poll, false, ['queues-live', 'queues-collector', 'queues-duplicates']],
    'off with health rules' => [LiveMode::Off, true, ['queues-live']],
    'stream' => [LiveMode::Stream, false, ['queues-collector', 'queues-duplicates']],
    'stream with health rules' => [LiveMode::Stream, true, []],
]);

it('keeps the deleteRules of the committed renders to the rules they leave out', function (LiveMode $mode): void {
    $deleted = array_column(Rendered::alerting($mode)['deleteRules'], 'uid');

    expect($deleted)->toBe($mode === LiveMode::Stream
        ? ['horizon-observability-collector', 'horizon-observability-duplicates']
        : ['horizon-observability-live', 'horizon-observability-collector', 'horizon-observability-duplicates'])
        ->and(array_unique(array_column(Rendered::alerting($mode)['deleteRules'], 'orgId')))->toBe([1]);
})->with(Rendered::modes());

it('renders the stall rule of ADDENDUM R4.11 in stream mode: the push age at scrape time', function (): void {
    $rules = array_column(committedRules(LiveMode::Stream), null, 'uid');
    $rule = $rules['horizon-observability-live'];

    // timestamp() wraps the raw selector: after an aggregation it would return the evaluation time
    expect($rule['data'][0]['model']['expr'])->toBe('max by (job) (timestamp(ho_exporter_live_push_timestamp_seconds{job=~".+"}) - ho_exporter_live_push_timestamp_seconds{job=~".+"})')
        ->and($rule['data'][0]['model']['instant'])->toBeTrue()
        ->and($rule['data'][1]['model']['conditions'][0]['evaluator'])->toBe(['type' => 'gt', 'params' => [30]])
        ->and($rule['condition'])->toBe('C')
        ->and($rule['for'])->toBe('1m')
        // Quiet before the stream ever ran: no push timestamp is exported until the first push
        ->and($rule['noDataState'])->toBe('OK')
        ->and($rule['annotations']['__panelId__'])->toBe((string) PanelRegistry::SUPERVISOR_PROCESSES)
        ->and($rule['annotations']['summary'])->toContain('{{ $labels.job }}');
});

it('scopes the stall rule to the pinned job and leaves it out of the other modes', function (): void {
    $rules = static fn (LiveMode $live, ?string $job = null): array => array_column(
        (new AlertRulesBuilder(new DashboardOptions(uid: 'queues', live: $live, job: $job, healthRules: true)))->build()['groups'][0]['rules'],
        null,
        'uid',
    );

    expect(array_keys($rules(LiveMode::Stream)))->toBe(['queues-no-metrics', 'queues-down', 'queues-long-wait', 'queues-failures', 'queues-live', 'queues-collector', 'queues-duplicates'])
        ->and($rules(LiveMode::Stream, 'app')['queues-live']['data'][0]['model']['expr'])
        ->toBe('max by (job) (timestamp(ho_exporter_live_push_timestamp_seconds{job=~"app"}) - ho_exporter_live_push_timestamp_seconds{job=~"app"})')
        ->and($rules(LiveMode::Poll))->not->toHaveKey('queues-live')
        ->and($rules(LiveMode::Off))->not->toHaveKey('queues-live');
});

it('renders the rule expressions of Arch Q5.3 per job', function (): void {
    $expressions = array_column(array_map(static fn (array $rule): array => ['uid' => $rule['uid'], 'expr' => $rule['data'][0]['model']['expr']], committedRules()), 'expr', 'uid');

    // Arch Q5.3 with the replica dedupe of step 10 around every selector
    expect($expressions)->toBe([
        'horizon-observability-no-metrics' => 'count(max by (job) (ho_horizon_status{job=~".+"}))',
        'horizon-observability-down' => 'min by (job) (max by (job) (ho_horizon_status{job=~".+"}))',
        'horizon-observability-long-wait' => 'max by (job, queue) (max by (job, connection, queue) (ho_queue_oldest_pending_job_age_seconds{job=~".+"}))'
            .' / on (job, queue) max by (job, queue) (max by (job, connection, queue) (ho_horizon_wait_threshold_seconds{job=~".+"}))',
        'horizon-observability-failures' => 'sum by (job, queue) (max by (job, connection, queue, job_class, status) (increase(ho_queue_jobs_total{job=~".+", status=~"failed|timed_out"}[15m])))'
            .' / clamp_min(sum by (job, queue) (max by (job, connection, queue, job_class, status) (increase(ho_queue_jobs_total{job=~".+", status=~"completed|failed|timed_out"}[15m]))), 100)',
    ]);
});

it('names the job in the summaries of the per-job rules', function (): void {
    $summaries = array_column(array_map(static fn (array $rule): array => ['uid' => $rule['uid'], 'summary' => $rule['annotations']['summary']], committedRules()), 'summary', 'uid');

    expect($summaries['horizon-observability-down'])->toContain('({{ $labels.job }})')
        ->and($summaries['horizon-observability-long-wait'])->toContain('{{ $labels.queue }} ({{ $labels.job }})')
        ->and($summaries['horizon-observability-failures'])->toContain('{{ $labels.queue }} ({{ $labels.job }})')
        ->and($summaries['horizon-observability-no-metrics'])->toBe('Prometheus has no ho_horizon_status: the metrics endpoint of the application is not scraped');
});

it('fits the rule uids into 40 characters for every valid dashboard uid', function (): void {
    $uid = str_repeat('u', 29);
    $rules = (new AlertRulesBuilder(new DashboardOptions(uid: $uid, live: LiveMode::Stream, healthRules: true)))->build()['groups'][0]['rules'];

    foreach ($rules as $rule) {
        expect(strlen($rule['uid']))->toBeLessThanOrEqual(40)
            ->and($rule['uid'])->toStartWith($uid.'-')
            ->and($rule['annotations']['__dashboardUid__'])->toBe($uid);
    }

    expect(max(array_map(strlen(...), AlertRulesBuilder::CORE_RULES)))->toBe(10);
});

it('links every rule to a panel of the committed dashboard', function (LiveMode $mode): void {
    $ids = array_column(PanelRegistry::panels(Rendered::dashboard($mode)), 'id');

    foreach (committedRules($mode) as $rule) {
        expect($ids)->toContain((int) $rule['annotations']['__panelId__']);
    }
})->with(Rendered::modes());

it('watches exactly the core rules in the Alerts tile and the alert sound', function (LiveMode $mode): void {
    $selectors = [];

    foreach (Rendered::expressions(alerting: [], mode: $mode) as $where => $expr) {
        preg_match_all('/GRAFANA_ALERTS\{grafana_rule_uid=~"([^"]*)"/', $expr, $matches);

        foreach ($matches[1] as $regex) {
            $selectors[$regex][] = (string) preg_replace('/ [A-Z]$/', '', $where);
        }
    }

    $core = $mode === LiveMode::Stream ? 'no-metrics|down|long-wait|failures|live' : 'no-metrics|down|long-wait|failures';

    expect(array_keys($selectors))->toBe(['horizon-observability-('.$core.')'])
        ->and(array_values(array_unique(reset($selectors))))->toBe(['panel '.PanelRegistry::ALERT_SOUND, 'panel '.PanelRegistry::ALERTS]);

    foreach (committedRules($mode) as $rule) {
        expect(preg_match('/^'.array_key_first($selectors).'$/', $rule['uid']))->toBe(1);
    }

    expect(preg_match('/^'.array_key_first($selectors).'$/', 'horizon-observability-collector'))->toBe(0)
        ->and(preg_match('/^'.array_key_first($selectors).'$/', 'horizon-observability-live'))->toBe($mode === LiveMode::Stream ? 1 : 0)
        ->and(preg_match('/^'.array_key_first($selectors).'$/', 'horizon-down'))->toBe(0);
})->with(Rendered::modes());

it('keeps "$" out of everything Grafana interpolates and uses the datasource placeholder', function (LiveMode $mode): void {
    foreach (committedRules($mode) as $rule) {
        expect($rule['uid'])->not->toContain('$')
            ->and($rule['title'])->not->toContain('$')
            ->and($rule['labels'] ?? [])->each->not->toContain('$')
            ->and($rule['data'][0]['datasourceUid'])->toBe('${HORIZON_OBSERVABILITY_PROMETHEUS_UID}')
            ->and($rule['data'][1]['datasourceUid'])->toBe('__expr__');
    }

    expect(Rendered::alerting($mode)['groups'][0]['folder'])->not->toContain('$');
})->with(Rendered::modes());

it('renders a literal datasource uid and keeps the office rule uids with --uid=horizon', function (): void {
    $directory = sys_get_temp_dir().'/ho-grafana-rules-'.bin2hex(random_bytes(4));

    try {
        $this->artisan('horizon-observability:grafana', ['path' => $directory, '--uid' => 'horizon', '--datasource-uid' => 'prometheus-prod'])->assertExitCode(0);
        $rules = json_decode((string) file_get_contents($directory.'/'.Render::ALERTING), true)['groups'][0]['rules'];
        $dashboard = (string) file_get_contents($directory.'/'.Render::DASHBOARD);

        expect(array_column($rules, 'uid'))->toBe(['horizon-no-metrics', 'horizon-down', 'horizon-long-wait', 'horizon-failures'])
            ->and(array_unique(array_map(static fn (array $rule): string => $rule['data'][0]['datasourceUid'], $rules)))->toBe(['prometheus-prod'])
            ->and($dashboard)->toContain('grafana_rule_uid=~\"horizon-(no-metrics|down|long-wait|failures)\"');
    } finally {
        array_map(unlink(...), glob($directory.'/*/*') ?: []);
        array_map(rmdir(...), glob($directory.'/*') ?: []);
        @rmdir($directory);
    }
});

it('refuses a datasource uid Grafana would not accept', function (string $uid): void {
    $this->artisan('horizon-observability:grafana', ['path' => sys_get_temp_dir().'/ho-unused', '--check' => true, '--datasource-uid' => $uid])
        ->expectsOutputToContain('--datasource-uid must be')
        ->assertExitCode(2);
})->with(['with a space' => ['prom prod'], 'too long' => [str_repeat('p', 41)], 'half a variable' => ['${PROM']]);

it('reads rule values only where the rule has one', function (): void {
    // In the no-data state $values.A.Value is nil, and a template function given nil logs "Error in expanding
    // template" on every evaluation (the pilot's failure rule did); `with $values.A` keeps a zero value
    $rules = (new AlertRulesBuilder(new DashboardOptions(live: LiveMode::Stream, healthRules: true)))->build()['groups'][0]['rules'];

    expect(array_column($rules, 'uid'))->toContain('horizon-observability-live');

    foreach ($rules as $rule) {
        $summary = $rule['annotations']['summary'];

        expect($summary)->not->toContain('$values.A.Value');

        if (str_contains($summary, '.Value')) {
            expect($summary)->toStartWith('{{ with $values.A }}')
                ->toMatch('/\{\{ else \}\}No [a-z]+ data for [^{]*(\{\{ \$labels\.(queue|job) \}\}[^{]*)+\{\{ end \}\}$/');
        }
    }
});
