<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Live\LivePanels;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Illuminate\Support\Facades\Artisan;

/*
 * horizon-observability:grafana (Arch Q5.1): renders the dashboard and the alert rules, and --check compares the files
 * with the render (Arch Q5.7 item 1: the committed assets equal the --defaults render, and --check is idempotent). The
 * package render polls the live snapshot (--live=poll, owner decision 9); the --live=off and --live=stream renders are
 * committed as fixtures (ADDENDUM Q9' WP8D).
 */

pest()->group('grafana');

function grafanaOutput(): string
{
    $directory = sys_get_temp_dir().'/ho-grafana-'.bin2hex(random_bytes(6));
    mkdir($directory);

    return $directory;
}

function removeGrafanaOutput(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($files as $file) {
        $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
    }

    rmdir($directory);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function runGrafana(array $parameters): array
{
    $status = Artisan::call('horizon-observability:grafana', $parameters);

    return [$status, Artisan::output()];
}

beforeEach(function (): void {
    $this->output = grafanaOutput();
});

afterEach(function (): void {
    removeGrafanaOutput($this->output);
});

it('keeps the committed assets equal to the --defaults render', function (): void {
    [$status, $output] = runGrafana(['path' => dirname(__DIR__, 2).'/resources/grafana', '--defaults' => true, '--recording-rules' => true, '--check' => true]);

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('up to date');
});

it('keeps the committed off and stream fixtures equal to their --defaults render', function (LiveMode $mode): void {
    [$status, $output] = runGrafana(['path' => Rendered::directory($mode), '--defaults' => true, '--live' => $mode->value, '--check' => true]);

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('up to date');
})->with([LiveMode::Off, LiveMode::Stream]);

it('renders the live mode it is given, poll by default', function (): void {
    foreach (['poll' => [], 'off' => ['--live' => 'off'], 'stream' => ['--live' => 'stream']] as $mode => $options) {
        [$status] = runGrafana(['path' => $this->output.'/'.$mode, '--defaults' => true, ...$options]);

        expect($status)->toBe(0);

        foreach ((new Render(new DashboardOptions(live: LiveMode::from($mode))))->files() as $file => $contents) {
            expect(file_get_contents($this->output.'/'.$mode.'/'.$file))->toBe($contents, $mode.' '.$file);
        }
    }
});

it('renders --live=off by default while the application serves no live endpoint (T13 QA round 2)', function (): void {
    config()->set('horizon-observability.live.enabled', false);
    app()->forgetInstance(Config::class);
    $dashboard = fn (): string => (string) file_get_contents($this->output.'/'.Render::DASHBOARD);

    [$status, $output] = runGrafana(['path' => $this->output]);
    $default = $dashboard();
    [$pollStatus, $pollOutput] = runGrafana(['path' => $this->output, '--live' => 'poll']);
    $poll = $dashboard();
    [$defaultsStatus, $defaultsOutput] = runGrafana(['path' => $this->output, '--defaults' => true]);

    expect($status)->toBe(0)
        ->and($output)->toContain('horizon-observability.live.enabled is false: rendering --live=off')
        ->and($default)->not->toContain(LivePanels::URL)
        ->and($default)->toBe((new Render(new DashboardOptions(live: LiveMode::Off)))->files()[Render::DASHBOARD])
        // An explicit live mode is rendered, with a warning
        ->and($pollStatus)->toBe(0)
        ->and($pollOutput)->toContain('the --live=poll tiles stay without live values until it is enabled')
        ->and($poll)->toContain(LivePanels::URL)
        // --defaults ignores the application config: poll, no warning
        ->and($defaultsStatus)->toBe(0)
        ->and($defaultsOutput)->not->toContain('live.enabled')
        ->and($dashboard())->toContain(LivePanels::URL);
});

it('subscribes the stream tiles to the configured stream id, the package default with --defaults', function (): void {
    config()->set('horizon-observability.live.stream.id', 'queues_prod');
    app()->forgetInstance(Config::class);
    $channels = function (array $parameters): array {
        [$status] = runGrafana(['path' => $this->output, '--live' => 'stream', ...$parameters]);
        $dashboard = (string) file_get_contents($this->output.'/'.Render::DASHBOARD);
        preg_match_all('#"channel": "([^"]+)"#', $dashboard, $matches);

        expect($status)->toBe(0);

        return array_values(array_unique($matches[1]));
    };

    expect($channels([]))->toBe(['stream/queues_prod/tiles', 'stream/queues_prod/delayed'])
        ->and($channels(['--live-stream-id' => 'other-1']))->toBe(['stream/other-1/tiles', 'stream/other-1/delayed'])
        ->and($channels(['--defaults' => true]))->toBe(['stream/horizon_observability/tiles', 'stream/horizon_observability/delayed']);
});

it('refreshes a poll render at --live-poll-interval', function (string $value, string $refresh, array $intervals): void {
    [$status] = runGrafana(['path' => $this->output, '--live-poll-interval' => $value]);
    $dashboard = json_decode((string) file_get_contents($this->output.'/'.Render::DASHBOARD), true, flags: JSON_THROW_ON_ERROR);

    expect($status)->toBe(0)
        ->and($dashboard['refresh'])->toBe($refresh)
        ->and($dashboard['timepicker']['refresh_intervals'])->toBe($intervals);
})->with([
    'default' => ['5s', '5s', ['5s', '10s', '15s', '30s', '1m', '5m']],
    'faster, with GF_DASHBOARDS_MIN_REFRESH_INTERVAL' => ['1s', '1s', ['1s', '5s', '10s', '15s', '30s', '1m', '5m']],
    'two seconds' => ['2s', '2s', ['2s', '5s', '10s', '15s', '30s', '1m', '5m']],
    'in minutes' => ['2m', '2m', ['5s', '10s', '15s', '30s', '1m', '2m', '5m']],
    'seconds of a whole minute' => ['60s', '1m', ['5s', '10s', '15s', '30s', '1m', '5m']],
]);

it('refreshes off and stream renders at S whatever the poll interval', function (string $live): void {
    [$status] = runGrafana(['path' => $this->output, '--live' => $live, '--live-poll-interval' => '2s', '--scrape-interval' => '30s']);
    $dashboard = json_decode((string) file_get_contents($this->output.'/'.Render::DASHBOARD), true, flags: JSON_THROW_ON_ERROR);

    expect($status)->toBe(0)
        ->and($dashboard['refresh'])->toBe('30s');
})->with(['off', 'stream']);

it('writes the dashboard and the alert rules', function (): void {
    [$status] = runGrafana(['path' => $this->output]);

    foreach ((new Render(new DashboardOptions))->files() as $file => $contents) {
        expect(file_get_contents($this->output.'/'.$file))->toBe($contents);
    }

    expect($status)->toBe(0)
        ->and(array_keys((new Render(new DashboardOptions))->files()))->toBe([Render::DASHBOARD, Render::ALERTING]);
});

it('writes the recording rules only with --recording-rules, and checks them (ADDENDUM R2.4 O9)', function (): void {
    [$status, $output] = runGrafana(['path' => $this->output, '--recording-rules' => true, '--scrape-interval' => '30s']);
    $render = (new Render(new DashboardOptions(scrapeInterval: 30, recordingRules: true)))->files();

    expect($status)->toBe(0, $output)
        ->and($output)->toContain(Render::RECORDING_RULES)
        ->toContain('and the Prometheus recording rules')
        ->and(file_get_contents($this->output.'/'.Render::RECORDING_RULES))->toBe($render[Render::RECORDING_RULES])
        ->and(runGrafana(['path' => $this->output, '--recording-rules' => true, '--scrape-interval' => '30s', '--check' => true])[0])->toBe(0);

    unlink($this->output.'/'.Render::RECORDING_RULES);
    [$status, $output] = runGrafana(['path' => $this->output, '--recording-rules' => true, '--scrape-interval' => '30s', '--check' => true]);

    expect($status)->toBe(1)
        ->and($output)->toContain('1 of 3 files')
        ->and(runGrafana(['path' => $this->output, '--scrape-interval' => '30s', '--check' => true])[0])->toBe(0);

    [$status] = runGrafana(['path' => $this->output.'/plain']);

    expect($status)->toBe(0)
        ->and(file_exists($this->output.'/plain/'.Render::RECORDING_RULES))->toBeFalse();
});

it('checks its own output idempotently', function (): void {
    runGrafana(['path' => $this->output]);
    $before = array_map(fn (string $file): string|false => file_get_contents($this->output.'/'.$file), [Render::DASHBOARD, Render::ALERTING]);

    expect(runGrafana(['path' => $this->output, '--check' => true])[0])->toBe(0)
        ->and(runGrafana(['path' => $this->output, '--check' => true])[0])->toBe(0)
        ->and(array_map(fn (string $file): string|false => file_get_contents($this->output.'/'.$file), [Render::DASHBOARD, Render::ALERTING]))->toBe($before);
});

it('fails the check with a summary for an outdated or missing file and writes nothing', function (): void {
    runGrafana(['path' => $this->output]);
    $dashboard = $this->output.'/'.Render::DASHBOARD;
    $edited = str_replace('"title": "Queues"', '"title": "Edited"', (string) file_get_contents($dashboard));
    file_put_contents($dashboard, $edited);
    unlink($this->output.'/'.Render::ALERTING);

    [$status, $output] = runGrafana(['path' => $this->output, '--check' => true]);

    expect($status)->toBe(1)
        ->and($output)->toContain('outdated')
        ->toContain('missing')
        ->toContain('2 of 2 files')
        ->toContain('instead of `"title": "Queues",`')
        ->and(file_get_contents($dashboard))->toBe($edited)
        ->and(file_exists($this->output.'/'.Render::ALERTING))->toBeFalse();
});

it('resolves a relative path against the application root, not the current directory (Arch Q5.1)', function (): void {
    $relative = 'ho-grafana-'.bin2hex(random_bytes(6)).'/nested';
    $previous = (string) getcwd();
    chdir($this->output);

    try {
        [$status] = runGrafana(['path' => $relative]);
    } finally {
        chdir($previous);
    }

    $written = base_path($relative);

    try {
        expect($status)->toBe(0)
            ->and(is_file($written.'/'.Render::DASHBOARD))->toBeTrue()
            ->and(is_file($written.'/'.Render::ALERTING))->toBeTrue()
            ->and(glob($this->output.'/*'))->toBe([]);
    } finally {
        removeGrafanaOutput(dirname($written));
    }
});

it('keeps an absolute path', function (): void {
    [$status] = runGrafana(['path' => $this->output.'/absolute/']);

    expect($status)->toBe(0)
        ->and(is_file($this->output.'/absolute/'.Render::DASHBOARD))->toBeTrue();
});

it('renders the identity, the folder and the datasource regexes it is given', function (): void {
    [$status] = runGrafana([
        'path' => $this->output,
        '--uid' => 'queues_prod-1',
        '--title' => 'Production queues',
        '--folder' => 'Queues',
        '--prometheus-datasource' => '/^Prometheus prod$/',
        '--infinity-datasource' => '/^App prod$/',
    ]);
    $dashboard = json_decode((string) file_get_contents($this->output.'/'.Render::DASHBOARD), true, flags: JSON_THROW_ON_ERROR);
    $rules = json_decode((string) file_get_contents($this->output.'/'.Render::ALERTING), true, flags: JSON_THROW_ON_ERROR);
    $variables = array_column($dashboard['templating']['list'], null, 'name');

    expect($status)->toBe(0)
        ->and($dashboard['uid'])->toBe('queues_prod-1')
        ->and($dashboard['title'])->toBe('Production queues')
        ->and($variables['datasource']['regex'])->toBe('/^Prometheus prod$/')
        ->and($variables['app_api']['regex'])->toBe('/^App prod$/')
        ->and($rules['groups'][0]['folder'])->toBe('Queues')
        ->and(array_column($rules['groups'][0]['rules'], 'uid'))->each->toStartWith('queues_prod-1-');
});

it('names the metrics with the prefix of the namespace, also with --defaults', function (string $namespace, string $expected): void {
    config()->set('horizon-observability.namespace', $namespace);
    app()->forgetInstance(Config::class);

    [$status] = runGrafana(['path' => $this->output, '--defaults' => true]);
    $files = array_map(fn (string $file): string => (string) file_get_contents($this->output.'/'.$file), [Render::DASHBOARD, Render::ALERTING]);

    expect($status)->toBe(0);

    foreach ($files as $contents) {
        expect($contents)->toContain($expected.'queue_jobs_total{')
            ->toContain($expected.'horizon_status');

        if ($namespace !== 'ho') {
            expect($contents)->not->toContain('ho_queue_')->not->toContain('ho_horizon_');
        }
    }
})->with([
    'default' => ['ho', 'ho_'],
    'office continuity' => ['office', 'office_'],
    'no prefix' => ['', ''],
]);

it('refuses invalid options and writes nothing', function (array $options, string $message): void {
    [$status, $output] = runGrafana(['path' => $this->output, ...$options]);

    expect($status)->toBe(2)
        ->and($output)->toContain($message)
        ->and(glob($this->output.'/*'))->toBe([]);
})->with([
    'uid with a dot' => [['--uid' => 'horizon.queues'], '--uid must be'],
    'uid of 30 characters' => [['--uid' => str_repeat('a', 30)], '--uid must be'],
    'empty uid' => [['--uid' => ''], '--uid must not be empty'],
    'empty title' => [['--title' => ' '], '--title must not be empty'],
    'title on two lines' => [['--title' => "Queues\nProduction"], '--title must be a single line'],
    'folder with an environment variable' => [['--folder' => 'Horizon $ENV'], '--folder must not contain "$"'],
    'unknown live mode' => [['--live' => 'fast'], '--live must be off, poll or stream'],
    'empty live mode' => [['--live' => ''], '--live must not be empty'],
    'poll interval of 0 s' => [['--live-poll-interval' => '0s'], '--live-poll-interval must be'],
    'poll interval over 5 minutes' => [['--live-poll-interval' => '301s'], '--live-poll-interval must be'],
    'poll interval in milliseconds' => [['--live-poll-interval' => '500ms'], '--live-poll-interval must be'],
    'poll interval without a unit' => [['--live-poll-interval' => '5'], '--live-poll-interval must be'],
    'poll interval in hours' => [['--live-poll-interval' => '1h'], '--live-poll-interval must be'],
    'stream id with a slash' => [['--live' => 'stream', '--live-stream-id' => 'a/b'], '--live-stream-id must be'],
    'stream id with a dot' => [['--live-stream-id' => 'queues.prod'], '--live-stream-id must be'],
    'stream id of 65 characters' => [['--live-stream-id' => str_repeat('s', 65)], '--live-stream-id must be'],
]);

it('summarises where a file differs from the render', function (): void {
    expect(Render::difference("a\nb\nc\n", "a\nb\nc\n"))->toBeNull()
        ->and(Render::difference("a\nb\nc\n", "a\nx\nc\n"))->toBe('lines 2-2 differ from 1 rendered line; first difference at line 2: `x` instead of `b`')
        ->and(Render::difference("a\nb\n", "a\nb\nc\nd\n"))->toBe('2 lines not in the render from line 3, first `c`')
        ->and(Render::difference("a\nb\nc\n", 'a'))->toBe('3 rendered lines missing after line 1, first `b`')
        ->and(Render::difference("a\n", "a\n\n"))->toBe('1 line not in the render from line 3, first ``');
});
