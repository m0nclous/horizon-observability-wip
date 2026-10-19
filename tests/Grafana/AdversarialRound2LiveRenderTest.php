<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Grafana\Live\LivePanels;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Illuminate\Support\Facades\Artisan;

/*
 * Failure isolation of the live tiles. The package render polls `${api_path}/live` (--live=poll), so the Overview row
 * now depends on the application API, which the --live=off render needed only for the collapsed job lists.
 *
 * - When the snapshot call fails (the route is absent with live.enabled = false, the Infinity datasource is missing or
 *   the application is unreachable, a token without access), "Config from query results" has no caption frame and
 *   Grafana names the Pending (#2) and Completed (#8) values after their query: both tiles show the whole PromQL
 *   expression as their caption (seen on Grafana 12.4.13 and 13.2.4). The off render
 *   guards #8 against this (renameByRegex '^-1$'); a live caption needs a fallback name too, e.g. a
 *   fieldConfig.defaults.displayName that configFromData overrides.
 * - horizon-observability:grafana renders --live=poll for an application whose live endpoint is disabled.
 */

pest()->group('grafana');

it('gives every live caption a fallback name for a failed snapshot call', function (string $mode): void {
    $missing = [];

    foreach (PanelRegistry::panels(Rendered::dashboard(Rendered::modes()[$mode][0])) as $panel) {
        foreach ($panel['transformations'] ?? [] as $transformation) {
            if (($transformation['id'] ?? null) !== 'configFromData') {
                continue;
            }

            $configRefId = $transformation['options']['configRefId'] ?? null;
            $fromLive = array_filter($panel['targets'] ?? [], static fn (array $target): bool => ($target['refId'] ?? null) === $configRefId
                && str_starts_with((string) ($target['url'] ?? ''), LivePanels::URL));
            $names = array_filter($transformation['options']['mappings'] ?? [], static fn (array $mapping): bool => ($mapping['handlerKey'] ?? null) === 'displayName');

            if ($fromLive !== [] && $names !== [] && ! is_string($panel['fieldConfig']['defaults']['displayName'] ?? null)) {
                $missing[] = $panel['title'];
            }
        }
    }

    expect($missing)->toBe([]);
})->with(['poll (package render)', 'stream']);

it('does not render live tiles for an application whose live endpoint is disabled', function (): void {
    config()->set('horizon-observability.live.enabled', false);
    app()->forgetInstance(Config::class);
    $directory = sys_get_temp_dir().'/ho-grafana-'.bin2hex(random_bytes(6));

    try {
        $status = Artisan::call('horizon-observability:grafana', ['path' => $directory]);
        $output = Artisan::output();
        $dashboard = is_file($directory.'/'.Render::DASHBOARD) ? (string) file_get_contents($directory.'/'.Render::DASHBOARD) : '';
    } finally {
        foreach ([Render::DASHBOARD, Render::ALERTING] as $file) {
            @unlink($directory.'/'.$file);
        }

        @rmdir($directory.'/dashboards');
        @rmdir($directory.'/alerting');
        @rmdir($directory);
    }

    // Either the render falls back to --live=off, or the command refuses or warns about live.enabled
    $livePanels = substr_count($dashboard, LivePanels::URL.'?');

    expect($livePanels === 0 || $status !== 0 || str_contains($output, 'live.enabled'))->toBeTrue(
        sprintf('exit %d, %d snapshot targets of a route that does not exist; output: %s', $status, $livePanels, trim($output)),
    );
});
