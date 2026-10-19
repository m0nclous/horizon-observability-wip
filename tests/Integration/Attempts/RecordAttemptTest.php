<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\CircuitBreaker;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;

/*
| record_attempt.lua through AttemptStore::record() (Arch Appendix D, Addendum D'). The redis group runs on phpredis
| and on predis (REDIS_CLIENT=predis): the pilot's pipelined EVAL threw a TypeError on predis and recorded nothing (C2).
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->keys = new Keys;
    $this->now = CarbonImmutable::createFromTimestamp(1_700_000_012.5);
});

it('records an attempt with one EVAL on the private storage client on every Redis client (C2)', function (): void {
    $commands = RedisCommands::start($this->app);

    expect(Storage::record(duration: 0.3, now: $this->now))->toBeTrue()
        ->and($commands->names())->toBe(['EVAL'])
        ->and($commands->commands()[0]['connection'])->toBe(RedisClients::STORAGE)
        ->and(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1']);
});

it('writes only hash-tagged package keys under the connection prefix', function (): void {
    Storage::record(duration: 0.3, startedAt: 1_700_000_010.0, readyAt: 1_700_000_009.0, now: $this->now);
    $prefix = TestCase::redisPrefix().'horizon:{horizon-observability}:v1:';

    expect(RedisKeys::all(TestCase::redisPrefix()))->toBe([
        $prefix.'attempts',
        $prefix.'created',
        $prefix.'duration:buckets',
        $prefix.'duration:sum',
        $prefix.'extremes:113333334:max',
        $prefix.'extremes:113333334:min',
        $prefix.'job_classes',
        $prefix.'live:ring:340000002',
        $prefix.'wait:buckets',
        $prefix.'wait:sum',
    ]);
});

it('counts every status per connection, queue and class', function (): void {
    foreach (AttemptStatus::cases() as $status) {
        Storage::record(status: $status, duration: null, now: $this->now);
    }

    Storage::record(status: AttemptStatus::Failed, duration: null, now: $this->now);
    Storage::record(jobClass: 'App\\Jobs\\Other', duration: null, now: $this->now, queue: 'slow');

    expect(Storage::hash($this->keys->attempts()))->toBe([
        'redis|default|App\\Jobs\\SendInvoice|completed' => '1',
        'redis|default|App\\Jobs\\SendInvoice|failed' => '2',
        'redis|default|App\\Jobs\\SendInvoice|released' => '1',
        'redis|default|App\\Jobs\\SendInvoice|retried' => '1',
        'redis|default|App\\Jobs\\SendInvoice|timed_out' => '1',
        'redis|slow|App\\Jobs\\Other|completed' => '1',
    ])->and(Storage::exists($this->keys->durationBuckets()))->toBeFalse();
});

it('buckets the duration non-cumulatively, sums it and keeps its exact value', function (): void {
    Storage::record(duration: 0.1234567891, now: $this->now);
    Storage::record(duration: 0.05, now: $this->now);
    Storage::record(duration: 7.5, now: $this->now);
    Storage::record(duration: 5000.0, now: $this->now);

    expect(Storage::hash($this->keys->durationBuckets()))->toBe([
        'redis|default|App\\Jobs\\SendInvoice|+Inf' => '1',
        'redis|default|App\\Jobs\\SendInvoice|0.05' => '1',
        'redis|default|App\\Jobs\\SendInvoice|0.25' => '1',
        'redis|default|App\\Jobs\\SendInvoice|10' => '1',
    ])->and((float) Storage::hash($this->keys->durationSum())['redis|default|App\\Jobs\\SendInvoice'])->toEqualWithDelta(5007.6734567891, 1e-9)
        ->and(Storage::hash($this->keys->extremes(113_333_334, 'min')))->toBe(['redis|default|App\\Jobs\\SendInvoice' => '0.05'])
        ->and(Storage::hash($this->keys->extremes(113_333_334, 'max')))->toBe(['redis|default|App\\Jobs\\SendInvoice' => '5000.0']);
});

it('keeps the duration digits a string cast would round', function (): void {
    Storage::record(duration: 0.123456789012345, now: $this->now);

    expect(Storage::hash($this->keys->extremes(113_333_334, 'min')))->toBe(['redis|default|App\\Jobs\\SendInvoice' => '0.123456789012345']);
});

it('writes the extremes of the 15-second bucket of the attempt end with a 300 s TTL (Addendum R3.4)', function (): void {
    Storage::record(duration: 1.0, now: CarbonImmutable::createFromTimestamp(1_700_000_024.9));
    Storage::record(duration: 2.0, now: CarbonImmutable::createFromTimestamp(1_700_000_025.0));

    expect(Storage::hash($this->keys->extremes(113_333_334, 'max')))->toBe(['redis|default|App\\Jobs\\SendInvoice' => '1.0'])
        ->and(Storage::hash($this->keys->extremes(113_333_335, 'max')))->toBe(['redis|default|App\\Jobs\\SendInvoice' => '2.0'])
        ->and(Storage::ttl($this->keys->extremes(113_333_334, 'min')))->toBeGreaterThan(290)->toBeLessThanOrEqual(300)
        ->and(Storage::ttl($this->keys->extremes(113_333_335, 'max')))->toBeGreaterThan(290)->toBeLessThanOrEqual(300);
});

it('records the wait of a first attempt from its readiness', function (): void {
    Storage::record(startedAt: 1_700_000_010.25, readyAt: 1_700_000_010.0, now: $this->now);
    Storage::record(startedAt: 1_700_000_010.0, readyAt: 1_699_999_990.0, now: $this->now);
    Storage::record(startedAt: 1_700_000_010.0, readyAt: 1_700_000_011.0, now: $this->now, queue: 'slow');

    expect(Storage::hash($this->keys->waitBuckets()))->toBe([
        'redis|default|0.25' => '1',
        'redis|default|30' => '1',
        'redis|slow|0.05' => '1',
    ])->and(Storage::hash($this->keys->waitSum()))->toBe([
        'redis|default' => '20.25',
        'redis|slow' => '0',
    ]);
});

it('records no wait without a start or without a readiness', function (): void {
    Storage::record(readyAt: 1_700_000_000.0, now: $this->now);
    Storage::record(startedAt: 1_700_000_010.0, now: $this->now);

    expect(Storage::exists($this->keys->waitBuckets()))->toBeFalse()
        ->and(Storage::exists($this->keys->waitSum()))->toBeFalse()
        ->and(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '2']);
});

it('buckets the wait with the configured bounds', function (): void {
    config()->set('horizon-observability.buckets', [1, 5]);
    $this->app->forgetInstance(Config::class);

    Storage::record(duration: 3.0, startedAt: 1_700_000_010.0, readyAt: 1_700_000_000.0, now: $this->now);

    expect(Storage::hash($this->keys->waitBuckets()))->toBe(['redis|default|+Inf' => '1'])
        ->and(Storage::hash($this->keys->durationBuckets()))->toBe(['redis|default|App\\Jobs\\SendInvoice|5' => '1']);
});

it('adds the attempts the breaker dropped to meta inside the same EVAL', function (): void {
    $clock = 0.0;
    $this->app->instance(CircuitBreaker::class, $breaker = new CircuitBreaker(app(FailureReporter::class), static function () use (&$clock): float {
        return $clock;
    }));
    $this->app->forgetInstance(AttemptStore::class);

    $breaker->attempt('attempts', static function (): void {
        throw new RuntimeException('down');
    });
    $clock = 1.0;
    Storage::record(now: $this->now);
    Storage::record(now: $this->now);
    $clock = 31.0;
    $commands = RedisCommands::start($this->app);

    expect(Storage::record(now: $this->now))->toBeTrue()
        ->and($commands->names())->toBe(['EVAL'])
        ->and(Storage::hash($this->keys->meta()))->toBe(['dropped' => '3'])
        ->and(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1']);
});

it('sanitises label values and never stores an empty one', function (): void {
    Storage::record(jobClass: "App\\Jobs\x1fSplit\n", duration: null, now: $this->now, connection: '', queue: "de\x1ffault");

    expect(Storage::hash($this->keys->attempts()))->toBe(['(unknown)|default|App\\JobsSplit|completed' => '1']);
});

it('returns false and stays silent for the worker when the script fails', function (): void {
    config()->set('database.redis.missing-storage');
    config()->set('horizon-observability.redis.connection', 'missing-storage');
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);
    $this->app->forgetInstance(AttemptStore::class);

    expect(Storage::record(now: $this->now))->toBeFalse()
        ->and(app(CircuitBreaker::class)->isOpen())->toBeTrue();
});
