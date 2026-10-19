<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Predis\Client;

/*
| T14 QA round 2 (adversarial): horizon-observability:flush reports what it did, also when DEL is refused.
|
| The page-by-page rewrite counts the deleted keys from the DEL replies. phpredis returns false for many error replies
| instead of throwing, which would read as "0 deleted" and end in "No package keys" while every key is still there.
| Probed on a read-only replica (REPLICAOF an unreachable master keeps the dataset and answers READONLY to DEL): both
| clients throw, flush exits 1 with "Deleting the package keys failed: ... READONLY" and the keys stay. It needs its
| own throwaway server, never the shared one (REPLICAOF makes it read-only): set REDIS_READONLY_PROBE_PORT, e.g.
| docker run -d --rm -p 127.0.0.1:16397:6379 redis:7.4-alpine and REDIS_READONLY_PROBE_PORT=16397.
*/

pest()->group('redis');

it('fails, and says so, when the server refuses DEL (read-only replica)', function (): void {
    $port = (int) getenv('REDIS_READONLY_PROBE_PORT');
    $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
    $raw = new Client(['host' => $host, 'port' => $port]);
    $prefix = TestCase::redisPrefix().'horizon:';
    $keys = new Keys;
    $names = array_map(static fn (string $key): string => $prefix.$key, [$keys->attempts(), $keys->meta(), $keys->liveLeader()]);

    foreach ($names as $name) {
        $raw->set($name, '1');
    }

    config()->set('database.redis.horizon.host', $host);
    config()->set('database.redis.horizon.port', $port);
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);
    $raw->executeRaw(['REPLICAOF', '127.0.0.1', '1']);

    try {
        $code = Artisan::call('horizon-observability:flush', ['--force' => true]);
        $output = Artisan::output();
    } finally {
        $raw->executeRaw(['REPLICAOF', 'NO', 'ONE']);
    }

    expect($code)->toBe(1)
        ->and($output)->toContain('Deleting the package keys failed')
        ->and($output)->toContain('READONLY')
        ->and($output)->not->toContain('No package keys')
        ->and((int) $raw->exists(...$names))->toBe(3);

    $raw->del($names);
})->skip(fn (): bool => getenv('REDIS_READONLY_PROBE_PORT') === false, 'needs a throwaway Redis in REDIS_READONLY_PROBE_PORT');
