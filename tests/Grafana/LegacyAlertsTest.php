<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\AlertRulesBuilder;
use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\LegacyFixtures;
use Symfony\Component\Yaml\Yaml;

/*
 * Arch Q5.6 M2: the alert rules come from the same profile as the dashboard and equal the legacy horizon.yml.
 */

pest()->group('grafana');

it('renders the legacy alert rules', function (): void {
    $rules = (new AlertRulesBuilder(LegacyFixtures::options()))->build();

    // toBe: same keys in the same order with the same scalar types
    expect($rules)->toBe(Yaml::parseFile(LegacyFixtures::path('horizon.yml')));
});

it('links every rule to a panel of the dashboard', function (): void {
    $options = LegacyFixtures::options();
    $dashboard = (new DashboardBuilder($options))->build();
    $ids = array_column(PanelRegistry::panels($dashboard), 'id');

    foreach ((new AlertRulesBuilder($options))->build()['groups'][0]['rules'] as $rule) {
        expect($rule['annotations']['__dashboardUid__'])->toBe($dashboard['uid'])
            ->and($ids)->toContain((int) $rule['annotations']['__panelId__']);
    }
});

it('takes the rule uids, folder, datasource and metric names from the options', function (): void {
    $rules = (new AlertRulesBuilder(LegacyFixtures::options([
        'uid' => 'queues',
        'metricPrefix' => 'acme_',
        'alertFolder' => 'Queues',
        'alertDatasourceUid' => 'prometheus',
    ])))->build();
    $group = $rules['groups'][0];
    $encoded = json_encode($rules, JSON_THROW_ON_ERROR);

    expect($group['folder'])->toBe('Queues')
        ->and(array_column($group['rules'], 'uid'))->toBe(['queues-no-metrics', 'queues-down', 'queues-long-wait', 'queues-failures'])
        ->and(array_unique(array_column(array_column($group['rules'], 'annotations'), '__dashboardUid__')))->toBe(['queues'])
        ->and($group['rules'][0]['data'][0]['datasourceUid'])->toBe('prometheus')
        ->and($encoded)->toContain('acme_horizon_status')->not->toContain('office_')->not->toContain('HPG_');
});

it('refuses a rule uid that Grafana would interpolate', function (): void {
    expect(fn (): array => (new AlertRulesBuilder(LegacyFixtures::options(['uid' => 'queues$x'])))->build())
        ->toThrow(LogicException::class, 'must not contain "$"');
});
