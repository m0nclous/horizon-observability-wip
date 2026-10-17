<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\AlertRulesBuilder;
use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Arch Q5.2 and Q5.6 step 4: the hidden prometheus_job variable scopes every package selector of the dashboard,
 * variable queries included, and the alert rules carry the rendered job regex (`.+`, or the exact --job).
 */

pest()->group('grafana');

/**
 * Package selectors of an expression without the job scope; a family name inside a `__name__` regex is not a selector.
 *
 * @return list<string>
 */
function unscopedSelectors(string $expr, string $scope): array
{
    $unscoped = [];
    $names = (string) preg_replace('/__name__=~"[^"]*"/', '__name__=~""', $expr);

    preg_match_all('/\bho_[a-z_]+(?:\{[^}]*\})?|\{__name__=~""[^}]*\}/', $names, $matches);

    foreach ($matches[0] as $selector) {
        if (! str_contains($selector, '{'.$scope) && ! str_contains($selector, '__name__=~"", '.$scope)) {
            $unscoped[] = $selector;
        }
    }

    return $unscoped;
}

it('scopes every package selector of the dashboard to the prometheus_job variable', function (): void {
    $unscoped = [];

    foreach (Rendered::expressions(alerting: []) as $where => $expr) {
        foreach (unscopedSelectors($expr, 'job=~"$prometheus_job"') as $selector) {
            $unscoped[] = $where.': '.$selector;
        }
    }

    // The variable itself lists the jobs
    expect($unscoped)->toBe(['variable prometheus_job: ho_horizon_status']);
});

it('scopes every selector of the alert rules to all jobs by default', function (): void {
    $unscoped = [];

    foreach (Rendered::expressions(dashboard: ['templating' => ['list' => []]]) as $where => $expr) {
        foreach (unscopedSelectors($expr, 'job=~".+"') as $selector) {
            $unscoped[] = $where.': '.$selector;
        }
    }

    expect($unscoped)->toBe([]);
});

it('defines the hidden prometheus_job variable with All as .+', function (): void {
    $variables = array_column(Rendered::dashboard()['templating']['list'], null, 'name');

    expect($variables['prometheus_job'])->toMatchArray([
        'current' => ['text' => 'All', 'value' => '$__all'],
        'definition' => 'label_values(ho_horizon_status, job)',
        'query' => 'label_values(ho_horizon_status, job)',
        'hide' => 2,
        'includeAll' => true,
        'allValue' => '.+',
        'multi' => false,
        'refresh' => 1,
        'type' => 'query',
    ])->and(array_keys($variables))->toBe([
        'datasource', 'prometheus_job', 'supervisor', 'batch_kind', 'queue', 'job_class', 'supervisor_title',
        'batch_title', 'horizon_url', 'tts_url', 'api_path', 'busy_window', 'app_api',
    ]);
});

it('selects a pinned job by default and scopes the alert rules to exactly it', function (): void {
    $options = new DashboardOptions(job: 'office-horizon.prod');
    $dashboard = (new DashboardBuilder($options))->build();
    $variables = array_column($dashboard['templating']['list'], null, 'name');
    $rules = (new AlertRulesBuilder($options))->build();
    $expressions = Rendered::expressions($dashboard, $rules);

    expect($variables['prometheus_job']['current'])->toBe(['text' => 'office-horizon.prod', 'value' => 'office-horizon.prod'])
        // The dashboard queries keep the variable, so ?var-prometheus_job= still switches the job
        ->and(implode("\n", Rendered::expressions($dashboard, [])))->not->toContain('office-horizon');

    foreach ($expressions as $where => $expr) {
        if (str_starts_with($where, 'rule ')) {
            expect(unscopedSelectors($expr, 'job=~"office-horizon\\\\.prod"'))->toBe([], $where);
        }
    }
});

it('quotes a job name as an exact regex', function (string $job, string $regex): void {
    expect(Promql::literalRegex($job))->toBe($regex)
        ->and(preg_match('/^'.str_replace('/', '\/', $regex).'$/', $job))->toBe(1);
})->with([
    ['app', 'app'],
    ['office-horizon', 'office-horizon'],
    ['a.b', 'a\.b'],
    ['x+y*z?', 'x\+y\*z\?'],
    ['(a|b)[c]{2}^$\\', '\(a\|b\)\[c\]\{2\}\^\$\\\\'],
]);

it('takes --job on the command line', function (): void {
    $directory = sys_get_temp_dir().'/ho-grafana-job-'.bin2hex(random_bytes(4));

    try {
        $this->artisan('horizon-observability:grafana', ['path' => $directory, '--job' => 'office-horizon'])->assertExitCode(0);
        $rules = (string) file_get_contents($directory.'/alerting/horizon-observability.json');
        $dashboard = json_decode((string) file_get_contents($directory.'/dashboards/horizon-observability.json'), true);

        expect($rules)->toContain('{job=~\"office-horizon\"')
            ->and(array_column($dashboard['templating']['list'], 'current', 'name')['prometheus_job']['value'])->toBe('office-horizon');
    } finally {
        array_map(unlink(...), glob($directory.'/*/*') ?: []);
        array_map(rmdir(...), glob($directory.'/*') ?: []);
        @rmdir($directory);
    }
});

it('keeps the job scope when Reset sets the filters to All', function (): void {
    $reset = Rendered::dashboard()['links'][0];

    expect($reset['title'])->toBe('Reset')
        ->and($reset['url'])->toEndWith('&${prometheus_job:queryparam}')
        ->toContain('var-supervisor=%24__all&var-batch_kind=%24__all&var-queue=%24__all&var-job_class=%24__all');
});
