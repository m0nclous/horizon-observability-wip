<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\LegacyReadyAt;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\Versions;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Events;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\CompletingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\Apps;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RunsWorker;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\HorizonServiceProvider;

/*
| The ready_at side channel (Arch Q6.8, C18): only on a legacy stack (payloads without `delay`, i.e. Laravel < 12.11
| or Horizon < 5.49), read and deleted inside the record EVAL. The pilot wrote one key per delayed dispatch and ran a
| GET+DEL round trip per started job on every stack.
*/

pest()->group('redis');

uses(RunsWorker::class);

beforeEach(function (): void {
    /**
     * The JobQueued listeners and the ready_at keys of a delayed dispatch in a separate app booted with $versions.
     *
     * @return array{listeners: bool, keys: list<string>}
     */
    $this->wiring = static fn (Versions $versions): array => Apps::inspect(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        [],
        static function (Application $app): array {
            /** @var Dispatcher $events */
            $events = $app->make('events');
            $listeners = $events->getRawListeners()[JobQueued::class] ?? [];

            CompletingJob::dispatch()->onConnection('redis')->delay(60);

            return [
                'listeners' => in_array([LegacyReadyAt::class, 'onQueued'], $listeners, true),
                'keys' => array_values(array_filter(Storage::packageKeys(), static fn (string $key): bool => str_contains($key, ':ready_at:'))),
            ];
        },
        static function (Application $app) use ($versions): void {
            // After the providers registered (their singleton would drop an earlier instance), before they boot.
            $app->booting(static fn () => $app->instance(Versions::class, $versions));
        },
    );
});

it('no keys on a modern stack (C18)', function (): void {
    $modern = new Versions(config(), '13.0.0', static fn (string $package): ?string => $package === 'laravel/horizon' ? '5.50.0.0' : null);

    expect(($this->wiring)($modern))->toBe(['listeners' => false, 'keys' => []]);
});

it('registers the listener and writes ready_at on a legacy stack', function (): void {
    $legacy = new Versions(config(), '12.10.0', static fn (string $package): ?string => $package === 'laravel/horizon' ? '5.50.0.0' : null);
    $wiring = ($this->wiring)($legacy);

    expect($wiring['listeners'])->toBeTrue()
        ->and($wiring['keys'])->toHaveCount(1);
});

it('passes no ready_at key to the record EVAL on a modern stack', function (): void {
    $this->app->instance(Versions::class, new Versions(config(), '13.0.0', static fn (string $package): ?string => $package === 'laravel/horizon' ? '5.50.0.0' : null));
    CompletingJob::dispatch()->onConnection('redis');
    $commands = RedisCommands::start($this->app);

    $this->runWorker();

    expect($commands->names(RedisClients::STORAGE))->toBe(['EVAL'])
        ->and(Storage::evalKeyCounts($commands->commands(RedisClients::STORAGE)))->toBe([10])
        ->and(Storage::waits())->toBe(1);
});

it('uses ready_at for the wait on a legacy stack and deletes it inside the EVAL (C18)', function (): void {
    $this->app->instance(Versions::class, new Versions(config(), '12.10.0'));

    // A legacy stack under test has the listener from the provider already.
    if (! in_array([LegacyReadyAt::class, 'onQueued'], Event::getRawListeners()[JobQueued::class] ?? [], true)) {
        Event::listen(JobQueued::class, [LegacyReadyAt::class, 'onQueued']);
    }

    $start = now()->startOfSecond()->toImmutable();
    $this->travelTo($start);

    CompletingJob::dispatch()->onConnection('redis')->delay(60);

    $keys = array_values(array_filter(Storage::packageKeys(), static fn (string $key): bool => str_contains($key, ':ready_at:')));

    expect($keys)->toHaveCount(1);

    $key = substr($keys[0], strlen(TestCase::redisPrefix().'horizon:'));

    expect(Storage::store()->reader()->get($key))->toBe((string) ($start->getTimestamp() + 60))
        ->and(Storage::ttl($key))->toBeGreaterThan(86_400)->toBeLessThanOrEqual(86_460);

    // The side channel is the later readiness here, so the wait must come from it: 70 - 65 = 5 s.
    Storage::store()->reader()->set($key, (string) ($start->getTimestamp() + 65));
    $this->travelTo($start->addSeconds(70));
    $commands = RedisCommands::start($this->app);

    $this->runWorker();

    expect($commands->names(RedisClients::STORAGE))->toBe(['EVAL'])
        ->and(Storage::evalKeyCounts($commands->commands(RedisClients::STORAGE)))->toBe([11])
        ->and(Storage::hash((new Keys)->waitSum()))->toBe(['redis|default' => '5'])
        ->and(Storage::exists($key))->toBeFalse();
});

it('skips connections whose driver is not redis, undelayed jobs and empty ids', function (string $connection, mixed $id, mixed $delay): void {
    app(LegacyReadyAt::class)->onQueued(Events::queued($connection, $id, $delay));

    expect(Storage::packageKeys())->toBe([]);
})->with([
    'sync' => ['sync', 'job-1', 60],
    'database' => ['database', 'job-1', 60],
    'unknown connection' => ['missing', 'job-1', 60],
    'no delay' => ['redis', 'job-1', 0],
    'null delay' => ['redis', 'job-1', null],
    'negative delay' => ['redis', 'job-1', -5],
    'a date in the past' => ['redis', 'job-1', CarbonImmutable::now()->subMinute()],
    'empty id' => ['redis', '', 60],
    'null id' => ['redis', null, 60],
]);
