<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\RedisCommands;

/*
| AttemptsCollector reads (Arch 8.4 "Reads"): zero-fill, cumulative buckets, a bucket change, the merged extremes,
| meta, stale-field HDEL and the cost per scrape, on both Redis clients. Values are absolute on every scrape (C3).
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->keys = new Keys;
    $this->now = CarbonImmutable::createFromTimestamp(1_700_000_012.5);
    // New series are exported as 0 for their first seconds (T16); the value tests scrape after that.
    $this->later = $this->now->addSeconds(AttemptsCollector::NEW_SERIES_HOLD_SECONDS);
    $this->series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice';
});

it('is the attempts collector and owns the catalog families of collector attempts', function (): void {
    Storage::record(duration: 0.3, startedAt: 1_700_000_010.0, readyAt: 1_700_000_009.0, now: $this->now);
    Storage::store()->reader()->hset($this->keys->meta(), 'live_push_at', '1700000011.5');
    $collector = app(AttemptsCollector::class);
    $families = [...$collector->collect(new ScrapeContext($this->now, 8.0))];

    expect($collector->name())->toBe('attempts')
        ->and(array_map(static fn (MetricFamily $family): Metric => $family->metric(), $families))->toBe(Metric::ofCollector('attempts'));
});

it('zero-fills all five statuses of every known series', function (): void {
    Storage::record(status: AttemptStatus::Completed, now: $this->now);
    Storage::record(status: AttemptStatus::Completed, now: $this->now);
    Storage::record(jobClass: 'App\\Jobs\\Other', status: AttemptStatus::TimedOut, duration: null, now: $this->now, queue: 'slow');

    expect(Storage::scrape($this->later)['samples']['queue_jobs_total'])->toBe([
        'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed' => 2,
        'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=released' => 0,
        'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=retried' => 0,
        'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=failed' => 0,
        'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=timed_out' => 0,
        'connection=redis,queue=slow,job_class=App\\Jobs\\Other,status=completed' => 0,
        'connection=redis,queue=slow,job_class=App\\Jobs\\Other,status=released' => 0,
        'connection=redis,queue=slow,job_class=App\\Jobs\\Other,status=retried' => 0,
        'connection=redis,queue=slow,job_class=App\\Jobs\\Other,status=failed' => 0,
        'connection=redis,queue=slow,job_class=App\\Jobs\\Other,status=timed_out' => 1,
    ]);
});

it('exports absolute values on every scrape (C3)', function (): void {
    Storage::record(now: $this->now);

    $first = Storage::scrape($this->later)['samples']['queue_jobs_total'][$this->series.',status=completed'];
    $second = Storage::scrape($this->later)['samples']['queue_jobs_total'][$this->series.',status=completed'];
    Storage::record(now: $this->now);
    $third = Storage::scrape($this->later)['samples']['queue_jobs_total'][$this->series.',status=completed'];

    expect([$first, $second, $third])->toBe([1, 1, 2]);
});

it('exports the duration histogram with cumulative buckets over the configured bounds', function (): void {
    config()->set('horizon-observability.buckets', [0.1, 1, 10]);
    $this->app->forgetInstance(Config::class);

    foreach ([0.05, 0.5, 0.7, 5.0, 50.0] as $duration) {
        Storage::record(duration: $duration, now: $this->now);
    }

    $samples = Storage::scrape($this->later)['samples'];

    expect($samples['queue_job_duration_seconds_bucket'])->toBe([
        $this->series.',le=0.1' => 1,
        $this->series.',le=1' => 3,
        $this->series.',le=10' => 4,
        $this->series.',le=+Inf' => 5,
    ])->and($samples['queue_job_duration_seconds_sum'][$this->series])->toEqualWithDelta(56.25, 1e-9)
        ->and($samples['queue_job_duration_seconds_count'][$this->series])->toBe(5);
});

it('folds stored buckets into the bounds configured now (a bucket change)', function (): void {
    config()->set('horizon-observability.buckets', [0.5, 2.5, 30]);
    $this->app->forgetInstance(Config::class);

    foreach ([0.2, 2.0, 20.0, 40.0] as $duration) {
        Storage::record(duration: $duration, now: $this->now);
    }

    config()->set('horizon-observability.buckets', [1, 60]);
    $this->app->forgetInstance(Config::class);

    expect(Storage::scrape($this->later)['samples']['queue_job_duration_seconds_bucket'])->toBe([
        $this->series.',le=1' => 1,
        $this->series.',le=60' => 3,
        $this->series.',le=+Inf' => 4,
    ]);
});

it('exports the wait histogram per queue', function (): void {
    Storage::record(startedAt: 1_700_000_010.0, readyAt: 1_700_000_009.9, now: $this->now);
    Storage::record(startedAt: 1_700_000_010.0, readyAt: 1_700_000_000.0, now: $this->now);

    $samples = Storage::scrape($this->later)['samples'];

    expect($samples['queue_job_wait_seconds_bucket']['connection=redis,queue=default,le=0.1'])->toBe(1)
        ->and($samples['queue_job_wait_seconds_bucket']['connection=redis,queue=default,le=10'])->toBe(2)
        ->and($samples['queue_job_wait_seconds_bucket']['connection=redis,queue=default,le=+Inf'])->toBe(2)
        ->and($samples['queue_job_wait_seconds_sum']['connection=redis,queue=default'])->toEqualWithDelta(10.1, 1e-6)
        ->and($samples['queue_job_wait_seconds_count']['connection=redis,queue=default'])->toBe(2);
});

it('exports the min and max of the last 10 extremes buckets (Addendum R3.4)', function (): void {
    $at = static fn (float $seconds): CarbonImmutable => CarbonImmutable::createFromTimestamp($seconds);

    Storage::record(duration: 4.0, now: $at(1_700_000_012.0));
    Storage::record(duration: 0.4, now: $at(1_700_000_012.0 - 30));
    Storage::record(duration: 9.0, now: $at(1_700_000_012.0 - 134));
    // The last second of the 11th bucket back: just outside the window.
    Storage::record(duration: 0.01, now: $at(1_699_999_874.9));
    Storage::record(duration: 99.0, now: $at(1_699_999_874.9));

    $samples = Storage::scrape($this->now)['samples'];

    expect($samples['queue_job_duration_min_seconds'])->toBe([$this->series => 0.4])
        ->and($samples['queue_job_duration_max_seconds'])->toBe([$this->series => 9.0]);
});

it('exports the dropped attempts always and the live push series once a push happened', function (): void {
    Storage::record(now: $this->now);

    $before = Storage::scrape($this->now)['samples'];
    Storage::store()->reader()->hmset($this->keys->meta(), ['dropped' => '7', 'live_push_at' => '1700000011.25', 'live_push_failures' => '3']);
    $after = Storage::scrape($this->now)['samples'];

    expect($before['exporter_attempts_dropped_total'])->toBe(['' => 0])
        ->and($before)->not->toHaveKeys(['exporter_live_push_timestamp_seconds', 'exporter_live_push_failures_total'])
        ->and($after['exporter_attempts_dropped_total'])->toBe(['' => 7])
        ->and($after['exporter_live_push_timestamp_seconds'])->toBe(['' => 1_700_000_011.25])
        ->and($after['exporter_live_push_failures_total'])->toBe(['' => 3]);
});

it('keeps the live push failures absent until the first successful push', function (): void {
    Storage::store()->reader()->hset($this->keys->meta(), 'live_push_failures', '4');

    expect(Storage::scrape($this->now)['samples'])->not->toHaveKeys(['exporter_live_push_timestamp_seconds', 'exporter_live_push_failures_total']);
});

it('reads on the short-timeout reader with a bounded number of commands', function (): void {
    Storage::record(duration: 0.3, startedAt: 1_700_000_010.0, readyAt: 1_700_000_009.0, now: $this->now);
    Storage::scrape($this->now);
    $commands = RedisCommands::start($this->app);

    Storage::scrape($this->now);

    // The guard, 5 counter hashes, the new-series hold (T16), the extremes, meta and the class set; the scrape path
    // never prunes the class set.
    expect($commands->names(RedisClients::READER.'horizon'))->toBe(['EVAL', 'HGETALL', 'HGETALL', 'HGETALL', 'HGETALL', 'HGETALL', 'EVAL', 'EVAL', 'HGETALL', 'ZRANGE'])
        ->and($commands->count())->toBe(10);
});

it('skips malformed fields and unknown statuses and deletes the malformed ones', function (): void {
    Storage::record(now: $this->now);
    $reader = Storage::store()->reader();
    $reader->hset($this->keys->attempts(), Keys::field('redis', 'default', 'App\\Jobs\\SendInvoice', 'exploded'), '5');
    $reader->hset($this->keys->attempts(), 'not-a-field', '1');
    $reader->hset($this->keys->durationBuckets(), Keys::field('redis', 'default', 'App\\Jobs\\SendInvoice', 'banana'), '2');

    $scrape = Storage::scrape($this->later);

    expect($scrape['failures'])->toBe([])
        ->and($scrape['samples']['queue_jobs_total'])->toHaveCount(5)
        ->and($scrape['samples']['queue_jobs_total'][$this->series.',status=completed'])->toBe(1)
        ->and($scrape['samples']['queue_job_duration_seconds_count'][$this->series])->toBe(1)
        ->and(array_keys(Storage::hash($this->keys->attempts())))->toBe([
            'redis|default|App\\Jobs\\SendInvoice|completed',
            'redis|default|App\\Jobs\\SendInvoice|exploded',
        ]);
});

it('aggregates fields whose labels sanitise to the same series', function (): void {
    $reader = Storage::store()->reader();
    $reader->zadd($this->keys->jobClasses(), 1_700_000_000, "App\\Jobs\\SendInvoice\n");
    $reader->zadd($this->keys->jobClasses(), 1_700_000_000, 'App\\Jobs\\SendInvoice');
    $reader->hset($this->keys->attempts(), Keys::field('redis', 'default', "App\\Jobs\\SendInvoice\n", 'failed'), '2');
    $reader->hset($this->keys->attempts(), Keys::field('redis', 'default', 'App\\Jobs\\SendInvoice', 'failed'), '3');

    expect(Storage::scrape($this->later)['samples']['queue_jobs_total'][$this->series.',status=failed'])->toBe(5);
});

it('keeps the data and reports a partial failure when the reset guard fails', function (): void {
    $directory = sys_get_temp_dir().'/ho-attempts-lua-'.getmypid();
    @mkdir($directory);

    foreach (['record_attempt', 'new_series', 'extremes_read'] as $script) {
        copy(dirname(__DIR__, 3).'/resources/lua/'.$script.'.lua', $directory.'/'.$script.'.lua');
    }

    try {
        $this->app->instance(Lua::class, new Lua($directory));
        Storage::record(now: $this->now);

        $scrape = Storage::scrape($this->later);

        expect($scrape['failures'])->toHaveCount(1)
            ->and($scrape['failures'][0])->toStartWith('attempts:reset_guard: Lua script reset_guard failed')
            ->and($scrape['samples']['queue_jobs_total'][$this->series.',status=completed'])->toBe(1);
    } finally {
        array_map(unlink(...), glob($directory.'/*.lua') ?: []);
        rmdir($directory);
    }
});

it('holds every series with a creation time and reports a partial failure when the hold script fails (T16)', function (): void {
    // E.g. TIME refused by an ACL (tests/Truth/FirstValueTruthTest): the data stays, nothing is released, so no
    // exported counter drops back to 0 when the script works again.
    $directory = sys_get_temp_dir().'/ho-attempts-lua-hold-'.getmypid();
    @mkdir($directory);

    foreach (['record_attempt', 'reset_guard', 'extremes_read'] as $script) {
        copy(dirname(__DIR__, 3).'/resources/lua/'.$script.'.lua', $directory.'/'.$script.'.lua');
    }

    try {
        Storage::record(now: $this->now);
        Storage::record(jobClass: 'App\\Jobs\\Old', now: $this->now->subHour());
        $this->app->instance(Lua::class, new Lua($directory));
        $this->app->forgetInstance(AttemptStore::class);

        $scrape = Storage::scrape($this->later->addHour());

        expect($scrape['failures'])->toHaveCount(1)
            ->and($scrape['failures'][0])->toStartWith('attempts:hold: Lua script new_series failed')
            ->and($scrape['samples']['queue_jobs_total'][$this->series.',status=completed'])->toBe(0)
            ->and($scrape['samples']['queue_jobs_total']['connection=redis,queue=default,job_class=App\\Jobs\\Old,status=completed'])->toBe(0)
            ->and(array_keys(Storage::hash($this->keys->created())))->toBe(['redis|default|App\\Jobs\\Old', 'redis|default|App\\Jobs\\SendInvoice']);
    } finally {
        array_map(unlink(...), glob($directory.'/*.lua') ?: []);
        rmdir($directory);
    }
});

it('fails the collector when the storage cannot be read', function (): void {
    config()->set('database.redis.unreachable', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);
    config()->set('horizon-observability.redis.connection', 'unreachable');
    config()->set('horizon-observability.redis.scrape_timeout', 0.2);
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);
    $this->app->forgetInstance(AttemptStore::class);

    $thrown = null;

    try {
        Storage::scrape($this->now);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    // The exporter turns this into exporter_collector_success{collector="attempts"} 0 (Arch Q1.3).
    expect($thrown)->toBeInstanceOf(Throwable::class);
});
