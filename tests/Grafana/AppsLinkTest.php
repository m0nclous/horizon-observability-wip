<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Several applications: every application renders its own dashboard (--uid, --job, --title), and each render carries
 * the common tag horizon-observability next to its own tags and an "Apps" dropdown of the dashboards with that tag, so a
 * viewer switches between the applications from any of them (#43). The dropdown keeps the time range, not the filters,
 * which belong to one application. Grafana 12.4.13 and 13.2.4 list the other tagged dashboards in it.
 */

pest()->group('grafana');

it('tags every render and links the dashboards of the other applications', function (LiveMode $mode): void {
    $dashboard = Rendered::dashboard($mode);
    $apps = array_values(array_filter($dashboard['links'], static fn (array $link): bool => $link['title'] === 'Apps'));

    expect($dashboard['tags'])->toBe(['horizon', 'queue', 'horizon-observability'])
        ->and(array_column($dashboard['links'], 'title'))->toBe(['Reset', 'Horizon', 'Apps'])
        ->and($apps)->toHaveCount(1)
        ->and($apps[0])->toMatchArray([
            'type' => 'dashboards',
            'tags' => ['horizon-observability'],
            'asDropdown' => true,
            'keepTime' => true,
            'includeVars' => false,
            'targetBlank' => false,
        ]);
})->with(Rendered::modes());

it('keeps the common tag and the dropdown for any identity and tags', function (array $options, array $tags): void {
    $dashboard = (new DashboardBuilder(new DashboardOptions(...$options)))->build();

    expect($dashboard['tags'])->toBe($tags)
        ->and(end($dashboard['links'])['tags'])->toBe([DashboardBuilder::APPS_TAG]);
})->with([
    'another app' => [['uid' => 'shop', 'title' => 'Shop', 'job' => 'shop-horizon'], ['horizon', 'queue', 'horizon-observability']],
    'own tags' => [['tags' => ['billing']], ['billing', 'horizon-observability']],
    'the tag already given' => [['tags' => ['horizon-observability', 'billing']], ['horizon-observability', 'billing']],
]);
