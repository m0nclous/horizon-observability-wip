<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Predis\Client;

/*
| T14 QA round 1 (adversarial): horizon-observability:flush.
|
| - Bounded cost: on a legacy stack (Laravel 11, or Laravel < 12.11 / Horizon < 5.49) every delayed job leaves a
|   `ready_at:<uuid>` key for its delay plus one day, so a busy app keeps hundreds of thousands of package keys. flush
|   holds every key name in PHP arrays (SCAN result, the prefix-stripped copy, array_unique, sort) before it deletes
|   anything: about 400 bytes per key, 246 MB peak for 600 000 keys, and "Allowed memory size of 134217728 bytes
|   exhausted" at FlushCommand.php:121 with php-cli's default memory_limit of 128M (measured on the dev Redis).
|   Scanning and deleting page by page (counting per kind on the way) keeps the memory flat.
| - The selected Redis database and the package keys of another database must survive.
*/

pest()->group('redis');

function adversarialFlushPrefix(): string
{
    return TestCase::redisPrefix().'horizon:';
}

/**
 * `$count` ready_at keys of the storage prefix, written server-side in one script.
 */
function adversarialFlushSeedReadyAt(int $count): void
{
    $prefix = adversarialFlushPrefix().(new Keys)->root().'ready_at:';

    RedisKeys::client()->eval(
        "for i = 1, tonumber(ARGV[2]) do redis.call('SET', ARGV[1] .. string.format('%08d-0000-4000-8000-%012d', i, i), '1') end return 1",
        0,
        $prefix,
        (string) $count,
    );
}

it('flushes 100 000 package keys with bounded memory', function (): void {
    adversarialFlushSeedReadyAt(100_000);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $code = Artisan::call('horizon-observability:flush', ['--force' => true]);
    $peak = memory_get_peak_usage() - $before;

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Deleted 100000 package keys (ready_at 100000)')
        ->and(RedisKeys::all(adversarialFlushPrefix().(new Keys)->root()))->toBe([])
        // A page of SCAN plus one DEL batch is far below 8 MB; the round 1 command peaks at about 35 MB here.
        ->and($peak)->toBeLessThan(8 * 1024 * 1024);
});

it('counts 100 000 package keys without --force with bounded memory', function (): void {
    adversarialFlushSeedReadyAt(100_000);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $code = Artisan::call('horizon-observability:flush', ['--no-interaction' => true]);
    $peak = memory_get_peak_usage() - $before;

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Found 100000 package keys (ready_at 100000)')
        ->and($peak)->toBeLessThan(8 * 1024 * 1024);
});

it('deletes only in the database of the storage connection', function (): void {
    $raw = RedisKeys::client();
    $other = new Client([
        'host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'database' => 3,
    ]);
    $key = adversarialFlushPrefix().(new Keys)->meta();

    try {
        $raw->set($key, '1');
        $other->set($key, '1');
        config()->set('database.redis.horizon.database', 3);
        $this->app->forgetInstance(RedisClients::class);
        $this->app->forgetInstance(Config::class);

        expect(Artisan::call('horizon-observability:flush', ['--force' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('Deleted 1 package keys (meta 1)')
            ->and((int) $other->exists($key))->toBe(0)
            ->and((int) $raw->exists($key))->toBe(1);
    } finally {
        $other->del([$key]);
    }
});
