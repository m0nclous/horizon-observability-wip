<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\AlertRulesBuilder;
use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Arch Q1.5, Q5.3 and Q5.6 step 11: --health-rules adds <uid>-collector and <uid>-duplicates; the Alerts tile and the
 * alert sound never watch them, and only the duplicates rule counts replicas (no dedupe around its selector).
 */

pest()->group('grafana');

/**
 * @return array<string, array<string, mixed>> uid => rule
 */
function healthRules(DashboardOptions $options): array
{
    return array_column((new AlertRulesBuilder($options))->build()['groups'][0]['rules'], null, 'uid');
}

it('renders no health rules by default', function (): void {
    expect(array_column(Rendered::alerting()['groups'][0]['rules'], 'uid'))->toHaveCount(4)
        ->and(healthRules(new DashboardOptions))->toHaveCount(4);
});

it('adds the collector and duplicates rules', function (): void {
    $rules = healthRules(new DashboardOptions(healthRules: true));
    $collector = $rules['horizon-observability-collector'];
    $duplicates = $rules['horizon-observability-duplicates'];

    expect(array_keys($rules))->toBe([
        'horizon-observability-no-metrics', 'horizon-observability-down', 'horizon-observability-long-wait',
        'horizon-observability-failures', 'horizon-observability-collector', 'horizon-observability-duplicates',
    ])
        ->and($collector['title'])->toBe('Metrics collector failing')
        ->and($collector['data'][0]['model']['expr'])->toBe('min by (job, collector) (max by (job, collector) (ho_exporter_collector_success{job=~".+"}))')
        ->and($collector['data'][1]['model']['conditions'][0]['evaluator'])->toBe(['type' => 'lt', 'params' => [1]])
        ->and($collector['for'])->toBe('15m')
        ->and($collector['noDataState'])->toBe('OK')
        ->and($duplicates['title'])->toBe('Duplicate metrics exporters')
        // Counts the replicas: the one selector without the dedupe
        ->and($duplicates['data'][0]['model']['expr'])->toBe('count by (job) (ho_horizon_status{job=~".+"})')
        ->and($duplicates['data'][1]['model']['conditions'][0]['evaluator'])->toBe(['type' => 'gt', 'params' => [1]])
        ->and($duplicates['for'])->toBe('10m')
        ->and($duplicates['noDataState'])->toBe('OK');

    foreach ([$collector, $duplicates] as $rule) {
        expect($rule['annotations']['__panelId__'])->toBe((string) PanelRegistry::HORIZON)
            ->and($rule['annotations']['summary'])->toContain('{{ $labels.job }}')
            ->and(strlen($rule['uid']))->toBeLessThanOrEqual(40);
    }
});

it('keeps the health rules out of the Alerts tile and the alert sound', function (): void {
    $options = new DashboardOptions(healthRules: true);
    $dashboard = json_encode((new DashboardBuilder($options))->build(), JSON_THROW_ON_ERROR);

    expect($dashboard)->toContain('grafana_rule_uid=~\"horizon-observability-(no-metrics|down|long-wait|failures)\"')
        ->not->toContain('collector|')
        ->not->toContain('duplicates');
});

it('scopes the health rules to a pinned job', function (): void {
    $rules = healthRules(new DashboardOptions(job: 'app', healthRules: true));

    expect($rules['horizon-observability-collector']['data'][0]['model']['expr'])->toContain('{job=~"app"}')
        ->and($rules['horizon-observability-duplicates']['data'][0]['model']['expr'])->toBe('count by (job) (ho_horizon_status{job=~"app"})');
});

it('takes --health-rules on the command line', function (): void {
    $directory = sys_get_temp_dir().'/ho-grafana-health-'.bin2hex(random_bytes(4));

    try {
        $this->artisan('horizon-observability:grafana', ['path' => $directory, '--health-rules' => true])->assertExitCode(0);
        $rules = json_decode((string) file_get_contents($directory.'/'.Render::ALERTING), true)['groups'][0]['rules'];

        expect(array_column($rules, 'uid'))->toContain('horizon-observability-collector', 'horizon-observability-duplicates')
            ->and(array_slice(AlertRulesBuilder::HEALTH_RULES, 0))->toBe(['collector', 'duplicates']);
    } finally {
        array_map(unlink(...), glob($directory.'/*/*') ?: []);
        array_map(rmdir(...), glob($directory.'/*') ?: []);
        @rmdir($directory);
    }
});
