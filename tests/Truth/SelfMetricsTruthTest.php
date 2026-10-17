<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Redis\CircuitBreaker;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
| Exporter truth (T16) for the exporter's own families: which collectors succeeded and how long they took, the
| versions it runs on, the configuration problems, the attempts it could not record and the live pushes. Each is
| compared with an independent source: a collector broken on purpose, the request's wall time, Composer's installed
| versions and PHP's, the invalid values set, the attempts run during a storage outage, the pushes a fake Grafana
| answered.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

it('reports a collector as failed exactly when one of its sources failed', function (): void {
    $directory = sys_get_temp_dir().'/ho-truth-lua-'.getmypid();
    @mkdir($directory);

    // Every script except queue_state: each queue read of the queues collector fails, nothing else does.
    foreach (glob(dirname(__DIR__, 2).'/resources/lua/*.lua') ?: [] as $script) {
        if (basename($script) !== 'queue_state.lua') {
            copy($script, $directory.'/'.basename($script));
        }
    }

    try {
        $scenario = Scenario::start($this);
        $healthy = new Samples($scenario->scrape());
        $this->app->instance(Lua::class, new Lua($directory));
        $broken = new Samples($scenario->scrape());
    } finally {
        array_map(unlink(...), glob($directory.'/*.lua') ?: []);
        rmdir($directory);
    }

    // batches: auto mode without a batch table is off, so it has no series at all.
    expect($healthy->series(Metric::CollectorSuccess->value))->toBe([
        Model::key(['collector' => 'attempts']) => 1.0,
        Model::key(['collector' => 'horizon']) => 1.0,
        Model::key(['collector' => 'job_classes']) => 1.0,
        Model::key(['collector' => 'queues']) => 1.0,
    ])->and($broken->series(Metric::CollectorSuccess->value))->toBe([
        Model::key(['collector' => 'attempts']) => 1.0,
        Model::key(['collector' => 'horizon']) => 1.0,
        Model::key(['collector' => 'job_classes']) => 1.0,
        Model::key(['collector' => 'queues']) => 0.0,
    ])->and($broken->series(Metric::QueuePendingJobs->value))->toBe([]);
});

it('reports collector durations that add up to at most the time of the scrape', function (): void {
    $scenario = Scenario::start($this);
    $started = hrtime(true);
    $samples = new Samples($scenario->scrape());
    $elapsed = (hrtime(true) - $started) / 1e9;
    $durations = $samples->series(Metric::CollectorDuration->value);

    expect(array_keys($durations))->toBe(array_keys($samples->series(Metric::CollectorSuccess->value)))
        ->and(min($durations))->toBeGreaterThanOrEqual(0.0)
        ->and(array_sum($durations))->toBeLessThanOrEqual($elapsed);
});

it('reports the versions Composer and PHP report', function (): void {
    $scenario = Scenario::start($this);
    $withoutV = static fn (?string $version): string => $version === null || $version === '' ? 'unknown' : (string) preg_replace('/^v(?=\d)/', '', $version);
    $client = (string) config('database.redis.client');
    $clientVersion = $client === 'predis' ? $withoutV(InstalledVersions::getPrettyVersion('predis/predis')) : (string) phpversion('redis');

    expect((new Samples($scenario->scrape()))->series(Metric::BuildInfo->value))->toBe([
        Model::key([
            'version' => InstalledVersions::isInstalled('sushi-market/horizon-observability') ? $withoutV(InstalledVersions::getPrettyVersion('sushi-market/horizon-observability')) : 'unknown',
            'php' => PHP_VERSION,
            'laravel' => Application::VERSION,
            'horizon' => $withoutV(InstalledVersions::getPrettyVersion('laravel/horizon')),
            'redis_client' => $client.' '.$clientVersion,
        ]) => 1.0,
    ]);
});

it('counts the invalid configuration values', function (array $invalid): void {
    foreach ($invalid as $key => $value) {
        config()->set('horizon-observability.'.$key, $value);
    }

    $scenario = Scenario::start($this);

    expect((new Samples($scenario->scrape()))->value(Metric::ConfigProblems->value))->toBe((float) count($invalid));
})->with([
    'none' => [[]],
    'one' => [['exceptions' => 'loud']],
    'three' => [['exceptions' => 'loud', 'queue_scan_limit' => -5, 'max_job_classes' => 'many']],
]);

it('counts every attempt run while the storage was unreachable once it is back', function (): void {
    $clock = 0.0;
    $this->app->instance(CircuitBreaker::class, new CircuitBreaker(app(FailureReporter::class), static function () use (&$clock): float {
        return $clock;
    }));
    $storage = static function (?string $connection): void {
        config()->set('horizon-observability.redis.connection', $connection);
        config()->set('horizon-observability.redis.worker_timeout', 0.2);

        foreach ([Config::class, RedisClients::class, AttemptStore::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
    };
    config()->set('database.redis.unreachable', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);
    $scenario = Scenario::start($this);
    $storage('unreachable');

    foreach (range(1, 5) as $ignored) {
        $scenario->dispatch(new TruthJob('Truth\\Lost'));
    }

    $scenario->dispatch(new TruthJob('Truth\\Lost', ['throw', 'complete'], tries: 2))->work();
    $lost = count($scenario->model->attempts());

    // The storage is back after the breaker's cooldown; the next attempt persists the dropped count.
    $storage(null);
    $clock = CircuitBreaker::COOLDOWN_SECONDS + 1;
    $scenario->dispatch(new TruthJob('Truth\\Kept'))->work()->advance(Model::HOLD_SECONDS);
    $samples = new Samples($scenario->scrape());

    expect($lost)->toBe(7)
        ->and($samples->value(Metric::AttemptsDropped->value))->toBe((float) $lost)
        ->and($samples->value(Metric::JobsTotal->value, ['connection' => 'redis', 'queue' => 'default', 'job_class' => 'Truth\\Kept', 'status' => 'completed']))->toBe(1.0)
        ->and($samples->labelSets(Metric::JobsTotal->value))->each->not->toMatchArray(['job_class' => 'Truth\\Lost']);
});

it('reports the live pushes Grafana answered: the failures and the time of the last success', function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana-truth:3000');
    config()->set('horizon-observability.live.stream.token', 'glsa_truth-token-0123456789');
    $this->app->forgetInstance(Config::class);
    LiveState::queues($this->app);
    $scenario = Scenario::start($this);

    expect((new Samples($scenario->scrape()))->value(Metric::LivePushTimestamp->value))->toBeNull();

    $answers = [500, 503, 200, 500];
    $pushedAt = null;
    $sequence = Http::fakeSequence();

    foreach ($answers as $status) {
        $sequence->push('', $status);
    }

    foreach ($answers as $status) {
        Artisan::call('horizon-observability:stream', ['--once' => true]);

        if ($status === 200) {
            $pushedAt = $scenario->unix();
        }

        $scenario->advance(10);
    }

    $samples = new Samples($scenario->scrape());

    Http::assertSentCount(count($answers));
    expect($samples->value(Metric::LivePushFailures->value))->toBe(3.0)
        ->and($samples->value(Metric::LivePushTimestamp->value))->toBe($pushedAt);
});
