<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\AlertRulesBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Arch Q5.3 and Q5.7 item 4: the committed alert rules (JSON provisioning file) are the four core rules, their uids
 * start with the dashboard uid and fit Grafana's 40 characters, they link to existing panels, the Alerts tile and
 * the alert sound watch exactly them, nothing Grafana would interpolate contains "$", and the datasource is the
 * environment placeholder.
 */

pest()->group('grafana');

/**
 * @return list<array<string, mixed>>
 */
function committedRules(): array
{
    return Rendered::alerting()['groups'][0]['rules'];
}

it('provisions the four core rules of the dashboard as JSON', function (): void {
    $group = Rendered::alerting()['groups'][0];

    expect(array_keys(Rendered::alerting()))->toBe(['apiVersion', 'groups'])
        ->and($group)->toMatchArray(['orgId' => 1, 'name' => 'Queues', 'folder' => 'Horizon', 'interval' => '1m'])
        ->and(array_column(committedRules(), 'uid'))->toBe([
            'horizon-observability-no-metrics',
            'horizon-observability-down',
            'horizon-observability-long-wait',
            'horizon-observability-failures',
        ])
        ->and(array_column(committedRules(), 'title'))->toBe(['No queue metrics', 'Horizon is down', 'Queue wait over 2x limit', 'High failure rate']);
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
    $rules = (new AlertRulesBuilder(new DashboardOptions(uid: $uid)))->build()['groups'][0]['rules'];

    foreach ($rules as $rule) {
        expect(strlen($rule['uid']))->toBeLessThanOrEqual(40)
            ->and($rule['uid'])->toStartWith($uid.'-')
            ->and($rule['annotations']['__dashboardUid__'])->toBe($uid);
    }

    expect(max(array_map(strlen(...), AlertRulesBuilder::CORE_RULES)))->toBe(10);
});

it('links every rule to a panel of the committed dashboard', function (): void {
    $ids = array_column(PanelRegistry::panels(Rendered::dashboard()), 'id');

    foreach (committedRules() as $rule) {
        expect($ids)->toContain((int) $rule['annotations']['__panelId__']);
    }
});

it('watches exactly the core rules in the Alerts tile and the alert sound', function (): void {
    $selectors = [];

    foreach (Rendered::expressions(alerting: []) as $where => $expr) {
        preg_match_all('/GRAFANA_ALERTS\{grafana_rule_uid=~"([^"]*)"/', $expr, $matches);

        foreach ($matches[1] as $regex) {
            $selectors[$regex][] = (string) preg_replace('/ [A-Z]$/', '', $where);
        }
    }

    expect(array_keys($selectors))->toBe(['horizon-observability-(no-metrics|down|long-wait|failures)'])
        ->and(array_values(array_unique(reset($selectors))))->toBe(['panel '.PanelRegistry::ALERT_SOUND, 'panel '.PanelRegistry::ALERTS]);

    foreach (committedRules() as $rule) {
        expect(preg_match('/^'.array_key_first($selectors).'$/', $rule['uid']))->toBe(1);
    }

    expect(preg_match('/^'.array_key_first($selectors).'$/', 'horizon-observability-collector'))->toBe(0)
        ->and(preg_match('/^'.array_key_first($selectors).'$/', 'horizon-down'))->toBe(0);
});

it('keeps "$" out of everything Grafana interpolates and uses the datasource placeholder', function (): void {
    foreach (committedRules() as $rule) {
        expect($rule['uid'])->not->toContain('$')
            ->and($rule['title'])->not->toContain('$')
            ->and($rule['labels'] ?? [])->each->not->toContain('$')
            ->and($rule['data'][0]['datasourceUid'])->toBe('${HORIZON_OBSERVABILITY_PROMETHEUS_UID}')
            ->and($rule['data'][1]['datasourceUid'])->toBe('__expr__');
    }

    expect(Rendered::alerting()['groups'][0]['folder'])->not->toContain('$');
});

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
