<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Render;
use Illuminate\Support\Facades\Artisan;

/*
 * Render options outside the defaults.
 * - The namespace is free ([A-Za-z_][A-Za-z0-9_]* or empty), and Promql::filtered() finds the package
 *   families by the prefix: a prefix that is a word of PromQL or of the dashboard (`sum_`, `by_`, `le_`, `job_`,
 *   `queue_`) or no prefix at all must give the default render with only the metric names changed.
 * - The command help lists every option with its whole description.
 */

pest()->group('grafana');

it('renders the same dashboard and rules for every namespace, with only the metric names changed', function (string $prefix): void {
    $default = (new Render(new DashboardOptions(healthRules: true)))->files();
    $files = (new Render(new DashboardOptions(metricPrefix: $prefix, healthRules: true)))->files();

    foreach ($files as $file => $contents) {
        expect(Render::difference((string) preg_replace('/\bho_(?=[a-z])/', $prefix, $default[$file]), $contents))->toBeNull($file);
    }
})->with([
    'no prefix' => [''],
    'office' => ['office_'],
    'upper case and digits' => ['Office2_'],
    'leading underscore' => ['_x_'],
    'a family word' => ['queue_'],
    'a label' => ['job_'],
    'a PromQL keyword' => ['by_'],
    'an aggregation' => ['sum_'],
    'the bucket label' => ['le_'],
]);

it('shows the whole description of every option in the help', function (): void {
    $definition = Artisan::all()['horizon-observability:grafana']->getDefinition();

    expect($definition->getOption('datasource-uid')->getDescription())
        ->toContain('${HORIZON_OBSERVABILITY_PROMETHEUS_UID}')
        ->toContain('interpolated by Grafana');

    foreach ($definition->getOptions() as $option) {
        // An unbalanced "(" shows a description cut at a "}" of the signature
        expect(substr_count($option->getDescription(), '('))->toBe(substr_count($option->getDescription(), ')'), '--'.$option->getName());
    }
});
