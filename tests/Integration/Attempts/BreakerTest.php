<?php

declare(strict_types=1);

use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\CircuitBreaker;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\CompletingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Support\RunsWorker;

/*
| An unreachable or hanging metrics Redis. The pilot paid a connect timeout (60 s by default) per job and per delayed
| dispatch and logged a warning per job. Now: a private client with redis.worker_timeout, and a breaker that skips
| writes for 30 s after a failure, so the worst case is one timeout per cooldown per worker.
*/

pest()->group('redis');

uses(RunsWorker::class);

beforeEach(function (): void {
    // A TCP server that accepts connections (in the kernel backlog) but never answers: every command times out.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($server === false) {
        $this->markTestSkipped('No local TCP server: '.$error);
    }

    $this->server = $server;
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    config()->set('database.redis.hanging', ['host' => '127.0.0.1', 'port' => $port, 'database' => 0, 'timeout' => 10, 'read_timeout' => 10]);
    config()->set('horizon-observability.redis.connection', 'hanging');
    config()->set('horizon-observability.redis.worker_timeout', 0.2);
    config()->set('queue.failed.driver', 'null');

    foreach ([Config::class, RedisClients::class, AttemptStore::class] as $abstract) {
        $this->app->forgetInstance($abstract);
    }

    $this->app->instance('log', $this->log = new LogRecorder);
    $this->clock = 0.0;
    $clock = &$this->clock;
    $this->app->instance(CircuitBreaker::class, $this->breaker = new CircuitBreaker(app(FailureReporter::class), static function () use (&$clock): float {
        return $clock;
    }));

    $this->timed = static function (Closure $run): float {
        $started = hrtime(true);
        $run();

        return (hrtime(true) - $started) / 1e9;
    };
});

afterEach(function (): void {
    fclose($this->server);
});

it('unreachable storage costs one timeout per cooldown', function (): void {
    $first = ($this->timed)(static function (): void {
        expect(Storage::record())->toBeFalse();
    });
    $skipped = ($this->timed)(static function (): void {
        foreach (range(1, 50) as $ignored) {
            expect(Storage::record())->toBeFalse();
        }
    });

    $this->clock = 31.0;
    $probe = ($this->timed)(static function (): void {
        expect(Storage::record())->toBeFalse();
    });
    $skippedAgain = ($this->timed)(static function (): void {
        foreach (range(1, 50) as $ignored) {
            expect(Storage::record())->toBeFalse();
        }
    });

    expect($first)->toBeGreaterThan(0.15)->toBeLessThan(2.0)
        ->and($skipped)->toBeLessThan(0.1)
        ->and($probe)->toBeGreaterThan(0.15)->toBeLessThan(2.0)
        ->and($skippedAgain)->toBeLessThan(0.1)
        ->and($this->log->messages('warning'))->toHaveCount(1)
        ->and($this->log->messages('warning')[0])->toStartWith('horizon-observability: breaker:attempts failed: ');
});

it('costs one timeout per cooldown when the default cache store is on the same Redis', function (): void {
    // The app's cache shares the hanging server (a common setup): the breaker's warning must not wait for it too.
    config()->set('database.redis.cache', [...config('database.redis.hanging'), 'timeout' => 3, 'read_timeout' => 3, 'read_write_timeout' => 3]);
    config()->set('cache.default', 'redis');
    app('redis')->purge('cache');

    $first = ($this->timed)(static function (): void {
        expect(Storage::record())->toBeFalse();
    });

    $this->clock = 31.0;
    $probe = ($this->timed)(static function (): void {
        expect(Storage::record())->toBeFalse();
    });

    expect($first)->toBeLessThan(2.0)
        ->and($probe)->toBeLessThan(2.0)
        ->and($this->log->messages('warning'))->toHaveCount(1)
        ->and($this->log->messages('warning')[0])->toStartWith('horizon-observability: breaker:attempts failed: ');
});

it('persists the dropped attempts once the storage recovers and logs one info line', function (): void {
    Storage::record();

    foreach (range(1, 9) as $ignored) {
        Storage::record();
    }

    config()->set('horizon-observability.redis.connection', 'horizon');

    foreach ([Config::class, RedisClients::class, AttemptStore::class] as $abstract) {
        $this->app->forgetInstance($abstract);
    }

    $this->clock = 31.0;

    expect(Storage::record())->toBeTrue()
        ->and(Storage::hash((new Keys)->meta()))->toBe(['dropped' => '10'])
        ->and(Storage::hash((new Keys)->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1'])
        ->and($this->log->messages('info'))->toBe(['horizon-observability: attempts recovered after 31s, 10 writes dropped.']);
});

it('lets jobs succeed while the storage hangs, with one log line', function (): void {
    foreach (range(1, 3) as $ignored) {
        CompletingJob::dispatch()->onConnection('redis');
    }

    $elapsed = ($this->timed)(fn () => $this->runWorker());

    expect(cache()->get('completing-job'))->toBe(3)
        ->and($elapsed)->toBeLessThan(2.0)
        ->and($this->log->messages('warning'))->toHaveCount(1)
        ->and($this->breaker->isOpen())->toBeTrue();
});
