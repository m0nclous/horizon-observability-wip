<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Symfony\Component\Yaml\Yaml;

/*
 * __requires lists Grafana 12.3.0 and exactly the datasources and panels the dashboard uses, with the plugin versions
 * the dev stack and CI provision (README, "Requirements"). In every mode: Infinity stays (the live modes read the
 * snapshot and the job lists with it), and stream adds nothing, Grafana Live and the -- Grafana -- datasource being
 * core.
 */

pest()->group('grafana');

it('requires Grafana 12.3 and exactly the plugins the dashboard uses', function (LiveMode $mode): void {
    $dashboard = Rendered::dashboard($mode);
    $used = ['grafana' => ['grafana']];

    foreach (PanelRegistry::panels($dashboard) as $panel) {
        if ($panel['type'] !== 'row') {
            $used['panel'][] = $panel['type'];
        }

        foreach ([$panel['datasource'] ?? null, ...array_column($panel['targets'] ?? [], 'datasource')] as $datasource) {
            // Grafana's built-in datasources (-- Dashboard --, -- Mixed --) are no requirement
            if (is_array($datasource) && $datasource['type'] !== 'datasource') {
                $used['datasource'][] = $datasource['type'];
            }
        }
    }

    foreach ($dashboard['templating']['list'] as $variable) {
        if ($variable['type'] === 'datasource') {
            $used['datasource'][] = $variable['query'];
        }
    }

    $required = [];

    foreach ($dashboard['__requires'] as $requirement) {
        $required[$requirement['type']][] = $requirement['id'];
    }

    $normalise = static function (array $ids): array {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    };
    $used = array_map($normalise, $used);
    $required = array_map($normalise, $required);
    ksort($used);
    ksort($required);

    expect(array_key_first($dashboard))->toBe('__requires')
        ->and($required)->toBe($used)
        ->and(array_column($dashboard['__requires'], 'version', 'id'))->toMatchArray(['grafana' => '12.3.0'])
        ->and($dashboard['__requires'])->toBe(Rendered::dashboard(LiveMode::Off)['__requires']);
})->with(Rendered::modes());

it('requires the plugin versions the dev stack provisions', function (): void {
    $compose = Yaml::parseFile(dirname(__DIR__, 2).'/docker-compose.yml');
    $installed = [];

    foreach (explode(',', $compose['services']['grafana']['environment']['GF_PLUGINS_PREINSTALL_SYNC']) as $plugin) {
        [$id, $version] = explode('@', $plugin);
        $installed[$id] = $version;
    }

    $versions = array_column(Rendered::dashboard()['__requires'], 'version', 'id');

    expect($installed)->toBe([
        'yesoreyeram-infinity-datasource' => $versions['yesoreyeram-infinity-datasource'],
        'marcusolsson-dynamictext-panel' => $versions['marcusolsson-dynamictext-panel'],
    ]);
});
