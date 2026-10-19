<?php

declare(strict_types=1);

use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Tests\TestCase;
use DF\HorizonObservability\Tests\Truth\Support\PrivateRedis;
use Illuminate\Support\Facades\Facade;
use Laravel\Horizon\Horizon;
use Symfony\Component\Process\Process;

/*
| T14 QA round 4 (adversarial): horizon-observability:check on a storage Redis that answers but refuses, and the
| Timeouts fix line applied as written.
|
| 1. Round 3 finding 2 was fixed with a list of error codes (RedisErrorReply::CODES and "unknown command `x`"); every
|    other error reply of a server that answered still falls through Diagnostics::collectorFailure() to "Check that
|    Redis is reachable with the settings in config/database.php (see Storage and Queues)", which the Storage line
|    ("reachable", ok) right above contradicts (owner decision 12: one actionable fix line per problem). Seen on
|    redis:7.4, redis:6.2, valkey:7.2 and valkey:8, both clients:
|    - a primary with min-replicas-to-write and no replica (a durability setting): every write of the scrape's reset
|      guard is refused with "NOREPLICAS Not enough good replicas to write.";
|    - a server that disables TIME (rename-command TIME "", the managed or proxied Redis the "does not offer" fix is
|      for): the package calls TIME only inside its scripts, where Redis answers "ERR Unknown Redis command called from
|      script" (6.2: "... from Lua script", Valkey 8: "Unknown command called from script"), which the "unknown
|      command `x`" pattern does not match.
|    The NOREPLICAS probe needs no extra server: it uses PrivateRedis (the leg's server image on CI). The TIME probe
|    starts its own container with --rename-command (TRUTH_REDIS_IMAGE, default redis:7.4-alpine) and is skipped
|    without Docker off CI.
| 2. The Timeouts fix of the Horizon connection names database.redis.<horizon.use> (e.g. default), the Redis connection
|    the app's redis queue connection uses too. With block_for 5 on that queue connection (Laravel's documented option,
|    BLPOP on the queue's :notify list), applying the line as written ('read_timeout' => 2.0, predis
|    'read_write_timeout' => 2.0) makes every blocking pop of an empty queue fail after 2 s ("read error on
|    connection", predis "Operation has timed out"): the worker reports an exception per idle poll.
*/

pest()->group('redis');

afterAll(function (): void {
    if (($GLOBALS['ho_adversarial_round4_server'] ?? null) instanceof PrivateRedis) {
        $GLOBALS['ho_adversarial_round4_server']->close();
        unset($GLOBALS['ho_adversarial_round4_server']);
    }

    if (is_string($GLOBALS['ho_adversarial_round4_timeless'] ?? null)) {
        (new Process(['docker', 'rm', '-f', '-v', $GLOBALS['ho_adversarial_round4_timeless']]))->run();
        unset($GLOBALS['ho_adversarial_round4_timeless'], $GLOBALS['ho_adversarial_round4_timeless_port']);
    }
});

beforeEach(function (): void {
    config()->set('horizon-observability.token', 'adversarial-round4-token-0123456789');
});

/**
 * The check results, by "section / name".
 *
 * @return array<string, CheckResult>
 */
function adversarialRound4Check(): array
{
    foreach ([Config::class, RedisClients::class, AttemptStore::class, BatchLinks::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $results = [];

    foreach (app(Diagnostics::class)->check() as $result) {
        $results[$result->section.' / '.$result->name] = $result;
    }

    return $results;
}

/**
 * The storage connection `ho-r4` on 127.0.0.1:<port> with the client.
 */
function adversarialRound4Storage(string $client, int $port): void
{
    config()->set('database.redis.client', $client);
    config()->set('database.redis.ho-r4', ['host' => '127.0.0.1', 'port' => $port, 'database' => 0]);
    config()->set('horizon-observability.redis.connection', 'ho-r4');
}

it('gives a write refused for missing replicas its own fix, not the reachability one Storage contradicts', function (string $client): void {
    if (! ($GLOBALS['ho_adversarial_round4_server'] ?? null) instanceof PrivateRedis) {
        $redis = PrivateRedis::create();

        if (! $redis instanceof PrivateRedis) {
            expect(PrivateRedis::onCi())->toBeFalse(PrivateRedis::unavailable());
            $this->markTestSkipped(PrivateRedis::unavailable());
        }

        $redis->start();
        $GLOBALS['ho_adversarial_round4_server'] = $redis;
    }

    $redis = $GLOBALS['ho_adversarial_round4_server'];
    expect($redis->command('FLUSHALL'))->toBe('+OK')
        // A primary that keeps writes only while a replica acknowledges them, and has none.
        ->and($redis->command('CONFIG', 'SET', 'min-replicas-to-write', '1'))->toBe('+OK');
    adversarialRound4Storage($client, $redis->port);

    try {
        $results = adversarialRound4Check();
    } finally {
        expect($redis->command('CONFIG', 'SET', 'min-replicas-to-write', '0'))->toBe('+OK');
    }

    $attempts = $results['Collectors / attempts'];

    expect($results['Storage / Connection']->status->value)->toBe('ok')
        ->and($attempts->status->value)->toBe('error')
        ->and($attempts->message)->toContain('NOREPLICAS')
        ->and(str_contains((string) $attempts->fix, 'reachable'))->toBeFalse(sprintf('Storage says "%s", the fix "%s"', $results['Storage / Connection']->message, $attempts->fix))
        ->and($attempts->fix)->toMatch('/replica/i');
})->with(['phpredis', 'predis']);

it('gives a server without TIME the fix for a command it does not offer, also inside the package scripts', function (string $client): void {
    if (! is_int($GLOBALS['ho_adversarial_round4_timeless_port'] ?? null)) {
        $docker = new Process(['docker', 'version', '--format', '{{.Server.Version}}']);
        $docker->run();

        if (! $docker->isSuccessful()) {
            expect(PrivateRedis::onCi())->toBeFalse('Docker is needed for a Redis without TIME.');
            $this->markTestSkipped('Docker is needed for a Redis without TIME.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        assert($socket !== false);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $image = getenv('TRUTH_REDIS_IMAGE');
        $name = 'ho-r4-timeless-'.getmypid().'-'.bin2hex(random_bytes(3));
        $run = new Process(['docker', 'run', '-d', '--name', $name, '-p', '127.0.0.1:'.$port.':6379', is_string($image) && $image !== '' ? $image : PrivateRedis::DEFAULT_IMAGE, 'redis-server', '--save', '', '--appendonly', 'no', '--rename-command', 'TIME', '']);
        $run->run();
        expect($run->isSuccessful())->toBeTrue($run->getErrorOutput());
        $GLOBALS['ho_adversarial_round4_timeless'] = $name;
        $deadline = microtime(true) + 30;

        do {
            usleep(100_000);
            $connection = @fsockopen('127.0.0.1', $port, $code, $message, 1.0);
            $ready = false;

            if ($connection !== false) {
                fwrite($connection, "PING\r\n");
                $ready = str_starts_with((string) fgets($connection), '+PONG');
                fclose($connection);
            }
        } while (! $ready && microtime(true) < $deadline);

        expect($ready)->toBeTrue('the Redis without TIME did not start');
        $GLOBALS['ho_adversarial_round4_timeless_port'] = $port;
    }

    adversarialRound4Storage($client, $GLOBALS['ho_adversarial_round4_timeless_port']);
    $results = adversarialRound4Check();
    $attempts = $results['Collectors / attempts'];

    expect($results['Storage / Connection']->status->value)->toBe('ok')
        // The new-series hold reads the storage Redis' clock with TIME inside new_series.lua (T16).
        ->and($attempts->status->value)->toBe('error')
        // Redis 7+ and Valkey 7.2 "Unknown Redis command called from script", 6.2 "... from Lua script", Valkey 8
        // "Unknown command called from script".
        ->and($attempts->message)->toMatch('/Unknown (Redis )?command called from/')
        ->and(str_contains((string) $attempts->fix, 'reachable'))->toBeFalse(sprintf('Storage says "%s", the fix "%s"', $results['Storage / Connection']->message, $attempts->fix))
        ->and($attempts->fix)->toMatch('/\bTIME\b/i');
})->with(['phpredis', 'predis']);

it('never gives the Redis connection of a blocking queue a read timeout shorter than its block_for', function (string $client): void {
    config()->set('database.redis.client', $client);
    // The app's redis queue connection on database.redis.default, blocking 5 s for a job (Laravel's block_for).
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'adversarial-round4', 'retry_after' => 90, 'block_for' => 5]);
    config()->set('horizon.use', 'default');
    Horizon::use('default');

    $result = adversarialRound4Check()['Timeouts / Horizon connection'];

    // Today: "Add 'timeout' => 2.0 and 'read_timeout' => 2.0 to database.redis.default in config/database.php; ..."
    // (predis: 'read_write_timeout'). The timeouts the line gives that connection, applied as written.
    if (preg_match('/^Add (.+?) to database\.redis\.default in config\/database\.php/', (string) $result->fix, $match) === 1) {
        preg_match_all("/'(\\w+)' => ([0-9.]+)/", $match[1], $entries, PREG_SET_ORDER);

        foreach ($entries as [, $key, $value]) {
            config()->set('database.redis.default.'.$key, (float) $value);
        }
    }

    Horizon::use('default');
    app()->forgetInstance('redis');
    app()->forgetInstance('queue');
    Facade::clearResolvedInstance('redis');
    Facade::clearResolvedInstance('queue');

    // What a worker of that queue connection does when the queue is empty.
    $started = microtime(true);
    $error = null;

    try {
        expect(app('queue')->connection('redis')->pop(TestCase::redisPrefix().'adversarial-round4-empty'))->toBeNull();
    } catch (Throwable $e) {
        $error = $e;
    }

    expect($error)->toBeNull(sprintf(
        'check advised "%s"; then an empty pop with block_for 5 failed after %.1f s: %s',
        $result->fix,
        microtime(true) - $started,
        $error instanceof Throwable ? $error::class.': '.$error->getMessage() : '',
    ));
})->with(['phpredis', 'predis']);
