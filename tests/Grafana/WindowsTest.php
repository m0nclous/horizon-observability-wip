<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricType;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Scrape-interval agnostic windows: the hidden scrape_interval variable is every Prometheus panel's min interval, the
 * busy window defaults to max(15s, 2 x S), the counter lookback is max(10m, 10 x S), the RPM window max(5m, 5 x S), and
 * the refresh follows S in off and stream modes and --live-poll-interval in poll mode.
 */

pest()->group('grafana');

/**
 * @return array<string, array<string, mixed>>
 */
function windowVariables(array $dashboard): array
{
    return array_column($dashboard['templating']['list'], null, 'name');
}

it('has no literal 15 s window and no day-long lookback on a counter', function (LiveMode $mode): void {
    $counters = array_filter(Metric::cases(), static fn (Metric $metric): bool => $metric->type() !== MetricType::Gauge);
    $names = implode('|', array_map(static fn (Metric $metric): string => 'ho_'.$metric->value.'(?:_bucket|_sum|_count)?', $counters));
    $dayLong = [];

    foreach (Rendered::expressions(mode: $mode) as $where => $expr) {
        expect($expr)->not->toContain('[15s]');

        if (preg_match('/\b(?:'.$names.')(?:\{(?:[^}"]|"(?:[^"\\\\]|\\\\.)*")*\})?\[(?:1d|7d|\d+h)\]/', $expr, $match) === 1) {
            $dayLong[] = $where.': '.$match[0];
        }
    }

    expect($dayLong)->toBe([])
        ->and(implode("\n", Rendered::expressions(mode: $mode)))->toContain('status=~"completed"}[10m] offset $__range');
})->with(Rendered::modes());

it('gives every panel with a Prometheus query the scrape interval as its min interval', function (LiveMode $mode, int $count): void {
    $prometheus = [];

    foreach (PanelRegistry::panels(Rendered::dashboard($mode)) as $panel) {
        // A Prometheus panel, or a Mixed live tile with its Prometheus sparkline
        $types = array_map(static fn (array $target): ?string => $target['datasource']['type'] ?? null, $panel['targets'] ?? []);

        if (($panel['datasource']['type'] ?? null) === 'prometheus' || in_array('prometheus', $types, true)) {
            $prometheus[] = $panel['id'];
            expect($panel['interval'] ?? null)->toBe('$scrape_interval', 'panel '.$panel['id']);
        } else {
            expect($panel)->not->toHaveKey('interval');
        }
    }

    expect($prometheus)->toHaveCount($count);
})->with([
    'poll: Supervisor Processes reads the snapshot only' => [LiveMode::Poll, 13],
    'off' => [LiveMode::Off, 14],
    'stream: Supervisor Processes, Pending and Delayed read Grafana Live' => [LiveMode::Stream, 11],
]);

it('defines the hidden scrape_interval and busy_window textboxes and refreshes at S, or at the poll interval', function (LiveMode $mode, string $refresh): void {
    $dashboard = Rendered::dashboard($mode);
    $variables = windowVariables($dashboard);
    $expressions = implode("\n", Rendered::expressions(mode: $mode));

    expect($variables['scrape_interval'])->toMatchArray(['hide' => 2, 'type' => 'textbox', 'query' => '10s', 'current' => ['text' => '10s', 'value' => '10s']])
        // Overridable in the URL: ?var-scrape_interval=30s
        ->and($variables['scrape_interval'])->not->toHaveKey('skipUrlSync')
        ->and($variables['busy_window'])->toMatchArray(['hide' => 2, 'type' => 'textbox', 'query' => '20s', 'current' => ['text' => '20s', 'value' => '20s']])
        // Busy workers over the window: the off render's Supervisor Processes tile and queue utilisation; the live
        // renders read both from the snapshot
        ->and(substr_count($expressions, '[$busy_window]') > 0)->toBe($mode === LiveMode::Off)
        ->and(substr_count($expressions, '[$busy_window]'))->toBe(substr_count($expressions, 'sum_over_time(ho_queue_jobs_reserved') * 2)
        ->and($dashboard['refresh'])->toBe($refresh)
        ->and($dashboard['timepicker']['refresh_intervals'])->toContain($refresh);
})->with([
    'poll (package render)' => [LiveMode::Poll, '5s'],
    'off' => [LiveMode::Off, '10s'],
    'stream' => [LiveMode::Stream, '10s'],
]);

it('derives the windows from the scrape interval', function (int $seconds, string $text, string $busy, string $lookback, int $rpm): void {
    $options = new DashboardOptions(scrapeInterval: $seconds, live: LiveMode::Stream);
    $dashboard = (new DashboardBuilder($options))->build();
    $variables = windowVariables($dashboard);
    $expressions = implode("\n", Rendered::expressions($dashboard, []));

    expect($variables['scrape_interval']['query'])->toBe($text)
        ->and($variables['busy_window']['query'])->toBe($busy)
        ->and($options->counterLookback())->toBe($lookback)
        ->and($options->rpmMinutes())->toBe($rpm)
        ->and($dashboard['refresh'])->toBe($text)
        ->and($dashboard['timepicker']['refresh_intervals'])->toContain($text)
        // The poll render refreshes at the poll interval whatever S
        ->and((new DashboardBuilder(new DashboardOptions(scrapeInterval: $seconds)))->build()['refresh'])->toBe('5s')
        ->and($expressions)->toContain('status=~"completed"}['.$lookback.'] offset')
        ->toContain('status=~"completed"}['.$rpm.'m])')
        ->toContain(' / '.$rpm.')');
})->with([
    'S = 5 s' => [5, '5s', '15s', '10m', 5],
    'S = 10 s (recommended)' => [10, '10s', '20s', '10m', 5],
    'S = 15 s' => [15, '15s', '30s', '10m', 5],
    'S = 45 s' => [45, '45s', '90s', '10m', 5],
    'S = 60 s' => [60, '1m', '2m', '10m', 5],
]);

it('lists the refresh in the time picker in order', function (): void {
    $dashboard = (new DashboardBuilder(new DashboardOptions(scrapeInterval: 45, live: LiveMode::Off)))->build();

    expect($dashboard['timepicker']['refresh_intervals'])->toBe(['5s', '10s', '15s', '30s', '45s', '1m', '5m']);
});

it('takes --scrape-interval on the command line', function (string $value, string $expected): void {
    $directory = sys_get_temp_dir().'/ho-grafana-s-'.bin2hex(random_bytes(4));

    try {
        $this->artisan('horizon-observability:grafana', ['path' => $directory, '--scrape-interval' => $value, '--live' => 'off'])->assertExitCode(0);
        $dashboard = json_decode((string) file_get_contents($directory.'/'.Render::DASHBOARD), true);

        expect(windowVariables($dashboard)['scrape_interval']['query'])->toBe($expected)
            ->and($dashboard['refresh'])->toBe($expected);
    } finally {
        array_map(unlink(...), glob($directory.'/*/*') ?: []);
        array_map(rmdir(...), glob($directory.'/*') ?: []);
        @rmdir($directory);
    }
})->with([['30s', '30s'], ['1m', '1m'], ['60s', '1m'], ['5s', '5s']]);

it('refuses a scrape interval outside 5 to 60 seconds', function (string $value): void {
    $this->artisan('horizon-observability:grafana', ['path' => sys_get_temp_dir().'/ho-unused', '--check' => true, '--scrape-interval' => $value])
        ->expectsOutputToContain('--scrape-interval must be')
        ->assertExitCode(2);
})->with(['4s', '61s', '2m', '0s', '10', 'ten', '1m30s', '']);
