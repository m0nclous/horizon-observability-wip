<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The hidden api_path, horizon_url and tts_url variables read horizon_info with a 7-day lookback, so they still have a
 * value while the application is not scraped. When the application changes one of them (horizon.path,
 * horizon-observability.path, the Horizon URL or the speech URL), the old series stays in that window for 7 days, the
 * variable gets two values and Grafana takes the first one it is given: the four job-list panels then read
 * `<old api_path>/jobs/<list>` (404) and the links open the old Horizon URL. A variable must give the value the
 * application exports now, and fall back to the lookback only when there is none.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

it('takes the current value of horizon_info after the application changed it', function (): void {
    $variables = [];

    foreach (Rendered::dashboard()['templating']['list'] as $variable) {
        $variables[$variable['name']] = substr($variable['query'], strlen('query_result('), -1);
    }

    $info = static fn (string $host, string $path): string => sprintf(
        'ho_horizon_info{job="app", instance="app:8080", url="https://%s/horizon", api_path="%s", tts_url="https://%s/piper"}',
        $host,
        $path,
        $host,
    );
    $test = static fn (string $name, string $label, string $value): array => [
        'expr' => Promtool::interpolate($variables[$name], '.+', 19 * 60, 15 * 60),
        'eval_time' => '19m',
        'exp_samples' => [['labels' => '{'.$label.'="'.$value.'"}', 'value' => 1]],
    ];

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => [
            // Until 10m the application exported the old paths, then it moved behind /admin on a new host
            ['series' => $info('old.test', '/horizon/observability'), 'values' => '1x10'],
            ['series' => $info('new.test', '/admin/horizon/observability'), 'values' => '_x10 1x9'],
        ],
        'promql_expr_test' => [
            $test('api_path', 'api_path', '/admin/horizon/observability'),
            $test('horizon_url', 'url', 'https://new.test/horizon'),
            $test('tts_url', 'tts_url', 'https://new.test/piper'),
        ],
    ]]);

    expect($status)->toBe(0, $output);
});

it('keeps the last value of horizon_info while the application is not scraped', function (): void {
    $variables = [];

    foreach (Rendered::dashboard()['templating']['list'] as $variable) {
        $variables[$variable['name']] = substr($variable['query'], strlen('query_result('), -1);
    }

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1h',
        'input_series' => [
            ['series' => 'ho_horizon_info{job="app", instance="app:8080", url="https://app.test/horizon", api_path="/horizon/observability"}', 'values' => '1x2'],
        ],
        'promql_expr_test' => [[
            'expr' => Promtool::interpolate($variables['api_path'], '.+', 48 * 3600, 3600),
            'eval_time' => '48h',
            'exp_samples' => [['labels' => '{api_path="/horizon/observability"}', 'value' => 1]],
        ]],
    ]]);

    expect($status)->toBe(0, $output);
});
