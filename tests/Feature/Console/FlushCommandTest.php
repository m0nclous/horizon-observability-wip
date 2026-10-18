<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/*
| PUBLIC horizon-observability:flush (Arch Q7, Appendix E, Addendum E'): deletes exactly the package keys of the
| storage connection, live:* included, found with SCAN on the connection prefix; never Horizon's or the app's keys,
| nor the package keys of another prefix. Asks first; refuses in production without --force.
*/

pest()->group('redis');

/**
 * Every kind of package key (Appendix E and E') under the storage prefix, plus keys that must survive.
 *
 * @return array{package: list<string>, others: list<string>}
 */
function flushSeed(string $prefix): array
{
    $keys = new Keys;
    $root = $keys->root();
    $raw = RedisKeys::client();
    $package = [
        $keys->attempts(),
        $keys->durationBuckets(),
        $keys->durationSum(),
        $keys->waitBuckets(),
        $keys->waitSum(),
        $keys->extremes(119480114, 'min'),
        $keys->extremes(119480114, 'max'),
        $keys->jobClasses(),
        $keys->meta(),
        $keys->batchLinks(),
        $keys->readyAt('3f1c2e9a-0000-4000-8000-000000000001'),
        $keys->readyAt('3f1c2e9a-0000-4000-8000-000000000002'),
        $keys->ring(358440343),
        $keys->liveSnapshot(sha1('full')),
        $keys->liveSnapshotStale(sha1('full')),
        $keys->liveSnapshotLock(sha1('full')),
        $keys->liveLeader(),
        $keys->liveDelayedWindow(),
    ];

    foreach ($package as $key) {
        $raw->set($prefix.$key, '1');
    }

    $others = [
        $prefix.'supervisors',                                  // Horizon's own keys share the prefix
        $prefix.'{horizon-observability}:v2:meta',              // another schema version
        TestCase::redisPrefix().'queues:default',               // the app's queue
        TestCase::redisPrefix().'other:'.$root.'meta',          // the package keys of another prefix
    ];

    foreach ($others as $key) {
        $raw->set($key, '1');
    }

    return ['package' => array_map(static fn (string $key): string => $prefix.$key, $package), 'others' => $others];
}

function flushPrefix(): string
{
    return TestCase::redisPrefix().'horizon:';
}

it('deletes exactly the package keys of the storage connection, live keys included', function (): void {
    $seed = flushSeed(flushPrefix());

    $code = Artisan::call('horizon-observability:flush', ['--force' => true]);
    $output = Artisan::output();
    $remaining = RedisKeys::all(TestCase::redisPrefix());
    $expected = $seed['others'];
    sort($expected);

    expect($code)->toBe(0)
        ->and($remaining)->toBe($expected)
        ->and($output)->toContain('Deleted 18 package keys (attempts 1, batch_links 1, duration 2, extremes 2, job_classes 1, live 6, meta 1, ready_at 2, wait 2)')
        ->and($output)->toContain('counter reset');
});

it('asks before deleting and keeps everything on no', function (): void {
    $seed = flushSeed(flushPrefix());

    $this->artisan('horizon-observability:flush')
        ->expectsConfirmation('Delete 18 package keys (attempts 1, batch_links 1, duration 2, extremes 2, job_classes 1, live 6, meta 1, ready_at 2, wait 2) from the Redis connection horizon?', 'no')
        ->expectsOutputToContain('Nothing was deleted.')
        ->assertExitCode(0);

    expect(RedisKeys::all(flushPrefix().'{horizon-observability}:v1:'))->toHaveCount(count($seed['package']));

    $this->artisan('horizon-observability:flush')
        ->expectsConfirmation('Delete 18 package keys (attempts 1, batch_links 1, duration 2, extremes 2, job_classes 1, live 6, meta 1, ready_at 2, wait 2) from the Redis connection horizon?', 'yes')
        ->assertExitCode(0);

    expect(RedisKeys::all(flushPrefix().'{horizon-observability}:v1:'))->toBe([]);
});

it('deletes nothing without --force when it cannot ask', function (): void {
    $seed = flushSeed(flushPrefix());

    $code = Artisan::call('horizon-observability:flush', ['--no-interaction' => true]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('run with --force to delete them')
        ->and(RedisKeys::all(flushPrefix().'{horizon-observability}:v1:'))->toHaveCount(count($seed['package']));
});

it('refuses in production without --force and flushes with it', function (): void {
    $seed = flushSeed(flushPrefix());
    $this->app['env'] = 'production';

    expect(Artisan::call('horizon-observability:flush', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('horizon-observability:flush --force')
        ->and(RedisKeys::all(flushPrefix().'{horizon-observability}:v1:'))->toHaveCount(count($seed['package']));

    expect(Artisan::call('horizon-observability:flush', ['--force' => true]))->toBe(0)
        ->and(RedisKeys::all(flushPrefix().'{horizon-observability}:v1:'))->toBe([]);
});

it('counts and deletes the keys of many SCAN pages per kind', function (): void {
    $prefix = flushPrefix().(new Keys)->root();
    // Written server-side: 1 500 ready_at and 700 live keys, several SCAN pages of FlushCommand::BATCH.
    RedisKeys::client()->eval(
        "for i = 1, 1500 do redis.call('SET', ARGV[1] .. 'ready_at:' .. i, '1') end for i = 1, 700 do redis.call('SET', ARGV[1] .. 'live:snapshot:' .. i, '1') end return 1",
        0,
        $prefix,
    );

    expect(Artisan::call('horizon-observability:flush', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Found 2200 package keys (live 700, ready_at 1500)');

    expect(Artisan::call('horizon-observability:flush', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Deleted 2200 package keys (live 700, ready_at 1500)')
        ->and(RedisKeys::all($prefix))->toBe([]);
});

it('says so when there is nothing to delete', function (): void {
    expect(Artisan::call('horizon-observability:flush', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('No package keys on the Redis connection horizon (prefix "'.flushPrefix().'").');
});

it('matches a prefix with glob characters literally', function (): void {
    $prefix = TestCase::redisPrefix().'we[i]rd*?:';
    config()->set('database.redis.horizon.options.prefix', $prefix);
    $this->app->forgetInstance(RedisClients::class);
    $this->app->forgetInstance(Config::class);
    $seed = flushSeed($prefix);
    $lookalike = TestCase::redisPrefix().'weirdXY:{horizon-observability}:v1:meta';
    RedisKeys::client()->set($lookalike, '1');

    expect(Artisan::call('horizon-observability:flush', ['--force' => true]))->toBe(0);

    $remaining = RedisKeys::all(TestCase::redisPrefix());
    $expected = [...$seed['others'], $lookalike];
    sort($expected);

    expect($remaining)->toBe($expected);
});

it('uses the configured storage connection and its prefix', function (): void {
    config()->set('database.redis.metrics', [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'database' => 0,
        'options' => ['prefix' => TestCase::redisPrefix().'metrics:'],
    ]);
    config()->set('horizon-observability.redis.connection', 'metrics');
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);
    $horizon = flushSeed(flushPrefix());
    flushSeed(TestCase::redisPrefix().'metrics:');

    expect(Artisan::call('horizon-observability:flush', ['--force' => true]))->toBe(0)
        ->and(RedisKeys::all(TestCase::redisPrefix().'metrics:{horizon-observability}:v1:'))->toBe([])
        ->and(RedisKeys::all(TestCase::redisPrefix().'metrics:'))->toBe([TestCase::redisPrefix().'metrics:supervisors', TestCase::redisPrefix().'metrics:{horizon-observability}:v2:meta'])
        ->and(RedisKeys::all(flushPrefix().'{horizon-observability}:v1:'))->toHaveCount(count($horizon['package']))
        ->and(Artisan::output())->toContain('from the Redis connection metrics');
});

it('fails with the connection error when the storage Redis is unreachable', function (): void {
    config()->set('database.redis.horizon', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);
    $this->app->forgetInstance(RedisClients::class);

    expect(Artisan::call('horizon-observability:flush', ['--force' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('The storage connection horizon cannot be read');
});
