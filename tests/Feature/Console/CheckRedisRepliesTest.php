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

/*
| horizon-observability:check on a storage Redis that answers but refuses: a read-only replica (a
| REDIS_HOST on a replica, or a primary a failover demoted), ACL refusals of the commands the package needs (TIME in
| the new-series hold and record_attempt, EVAL, the package keys) and a server at its maxmemory. Each failure gets the
| fix for its reply, never "check that Redis is reachable", which the Storage line ("reachable") contradicts; for an
| ACL refusal the fix names the refused command and the rule, and running that rule ends the failure. One private
| Redis (PrivateRedis: the leg's server image on CI, so Redis 6.2 to 8 and Valkey word their replies) serves the
| file, never the shared server; both clients.
*/

pest()->group('redis');

afterAll(function (): void {
    if (($GLOBALS['ho_reply_server'] ?? null) instanceof PrivateRedis) {
        $GLOBALS['ho_reply_server']->close();
        unset($GLOBALS['ho_reply_server']);
    }
});

/**
 * The file's private Redis, started on first use, with the user `ho` (password `secret`) allowed everything again,
 * a primary without a memory limit and no keys; it is the package storage `ho-private`.
 */
function replyServer(TestCase $test, string $client): PrivateRedis
{
    if (! ($GLOBALS['ho_reply_server'] ?? null) instanceof PrivateRedis) {
        $redis = PrivateRedis::create();

        if (! $redis instanceof PrivateRedis) {
            expect(PrivateRedis::onCi())->toBeFalse(PrivateRedis::unavailable());
            $test->markTestSkipped(PrivateRedis::unavailable());
        }

        $redis->start();
        $GLOBALS['ho_reply_server'] = $redis;
    }

    $redis = $GLOBALS['ho_reply_server'];

    foreach ([['REPLICAOF', 'NO', 'ONE'], ['CONFIG', 'SET', 'maxmemory', '0'], ['ACL', 'DELUSER', 'ho'], ['ACL', 'SETUSER', 'ho', 'on', '>secret', '~*', '+@all'], ['FLUSHALL']] as $command) {
        expect($redis->command(...$command))->toBeIn(['+OK', ':0', ':1'], implode(' ', $command));
    }

    config()->set('database.redis.client', $client);
    config()->set('database.redis.ho-private', ['host' => '127.0.0.1', 'port' => $redis->port, 'database' => 0, 'username' => 'ho', 'password' => 'secret']);
    config()->set('horizon-observability.redis.connection', 'ho-private');
    config()->set('horizon-observability.token', 'redis-replies-check-token-0123456789');

    return $redis;
}

/**
 * The check results, by "section / name".
 *
 * @return array<string, CheckResult>
 */
function replyCheck(): array
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
 * Run the ACL rule a fix line names (`ACL SETUSER <user> <rule>`) on the server.
 */
function replyRunRule(PrivateRedis $redis, ?string $fix): void
{
    expect(preg_match("/ACL SETUSER (\\S+) '?([^' ]+)'?/", (string) $fix, $match))->toBe(1, (string) $fix)
        ->and($redis->command('ACL', 'SETUSER', $match[1], $match[2]))->toBe('+OK');
}

it('points a read-only replica at the writable primary', function (string $client): void {
    $redis = replyServer($this, $client);
    expect($redis->command('REPLICAOF', '127.0.0.1', '1'))->toBe('+OK');

    $results = replyCheck();

    expect($results['Storage / Connection']->status->value)->toBe('ok')
        ->and($results['Collectors / attempts']->status->value)->toBe('error')
        ->and($results['Collectors / attempts']->message)->toContain('READONLY')
        ->and($results['Collectors / attempts']->fix)->toBe("Point database.redis.ho-private at the writable primary: it answers as a read-only replica (or a primary a failover demoted), which refuses the package's writes.");

    // What pointing the connection at the primary gives.
    expect($redis->command('REPLICAOF', 'NO', 'ONE'))->toBe('+OK')
        ->and(replyCheck()['Collectors / attempts']->status->value)->toBe('ok');
})->with(['phpredis', 'predis']);

it('names a command an ACL refuses and the rule that allows it', function (string $client, string $command, ?string $source): void {
    $redis = replyServer($this, $client);
    expect($redis->command('ACL', 'SETUSER', 'ho', '-'.$command))->toBe('+OK');

    $results = replyCheck();
    $attempts = $results['Collectors / attempts'];

    expect($results['Storage / Connection']->status->value)->toBe('ok')
        ->and($attempts->status->value)->toBe('error')
        // Redis 6.2 inside a script names only the line of the command.
        ->and($attempts->message)->toStartWith($source === null ? 'failed' : 'failed ('.$source.'): ')->toMatch("/permissions to run the '{$command}' command|can't run this command/")
        ->and($attempts->fix)->toBe(sprintf(
            'Allow %s for the Redis user ho of database.redis.ho-private: ACL SETUSER ho +%s (the package runs EVAL, TIME and INFO besides the hash, list, sorted set and string commands on its keys).',
            strtoupper($command),
            $command,
        ));

    replyRunRule($redis, $attempts->fix);

    expect(replyCheck()['Collectors / attempts']->status->value)->toBe('ok');
})->with(['phpredis', 'predis'])->with([
    // The new-series hold reads the storage Redis' clock; Redis 7+ and Valkey answer "ERR ACL failure in
    // script: ..." inside the script, Redis 6.2 NOPERM.
    'TIME' => ['time', 'attempts:hold'],
    // Every script of the scrape: the first one that is not caught fails the whole collector.
    'EVAL' => ['eval', null],
]);

it('names the key pattern when an ACL refuses the package keys', function (string $client): void {
    $redis = replyServer($this, $client);
    expect($redis->command('ACL', 'SETUSER', 'ho', 'resetkeys', '~other:*'))->toBe('+OK');

    $storage = replyCheck()['Storage / Connection'];

    expect($storage->status->value)->toBe('error')
        ->and($storage->message)->toStartWith('ho-private answers with an error: ')->toContain('NOPERM')
        ->and($storage->fix)->toBe(sprintf("Allow the keys of database.redis.ho-private for its Redis user ho: ACL SETUSER ho '~%s*'.", TestCase::redisPrefix()));

    replyRunRule($redis, $storage->fix);
    $results = replyCheck();

    expect($results['Storage / Connection']->status->value)->toBe('ok')
        ->and($results['Collectors / attempts']->status->value)->toBe('ok');
})->with(['phpredis', 'predis']);

it('tells a server at its maxmemory to free memory', function (string $client): void {
    $redis = replyServer($this, $client);
    expect($redis->command('CONFIG', 'SET', 'maxmemory-policy', 'noeviction'))->toBe('+OK')
        ->and($redis->command('CONFIG', 'SET', 'maxmemory', '1'))->toBe('+OK');

    $results = replyCheck();

    expect($results['Storage / Connection']->status->value)->toBe('ok')
        ->and($results['Collectors / attempts']->message)->toContain('OOM')
        ->and($results['Collectors / attempts']->fix)->toBe("Free memory on the Redis of database.redis.ho-private or raise its maxmemory: at maxmemory it refuses the package's writes (OOM).");

    expect($redis->command('CONFIG', 'SET', 'maxmemory', '0'))->toBe('+OK')
        ->and(replyCheck()['Collectors / attempts']->status->value)->toBe('ok');
})->with(['phpredis', 'predis']);

it('asks for the password Redis expects instead of reporting Redis down', function (string $client): void {
    replyServer($this, $client);
    config()->set('database.redis.ho-private.password', 'not-the-password');

    $storage = replyCheck()['Storage / Connection'];

    expect($storage->status->value)->toBe('error')
        // predis 3 retries a refused HELLO as AUTH <password>, which Redis refuses for the default user.
        ->and($storage->message)->toStartWith('ho-private answers with an error: ')->toMatch('/WRONGPASS|ERR AUTH/')
        ->and($storage->fix)->toBe('Set the password (and username) Redis expects in database.redis.ho-private (REDIS_PASSWORD, REDIS_USERNAME).');
})->with(['phpredis', 'predis']);

it('names the queue connection and its prefix when an ACL refuses the queue keys', function (string $client): void {
    $redis = replyServer($this, $client);
    $queuePrefix = 'hoq_'.getmypid().':';
    // Horizon's queue on its own connection of the same server, with its own prefix; the package storage keys stay allowed.
    config()->set('database.redis.ho-queue', ['host' => '127.0.0.1', 'port' => $redis->port, 'database' => 0, 'username' => 'ho', 'password' => 'secret', 'prefix' => $queuePrefix]);
    config()->set('queue.connections.ho-queue', ['driver' => 'redis', 'connection' => 'ho-queue', 'queue' => 'default']);
    config()->set('horizon.environments.testing', ['supervisor-1' => ['connection' => 'ho-queue', 'queue' => ['default'], 'maxProcesses' => 1]]);
    expect($redis->command('ACL', 'SETUSER', 'ho', 'resetkeys', '~'.TestCase::redisPrefix().'*'))->toBe('+OK');

    $results = replyCheck();
    $fix = sprintf("Allow the keys of database.redis.ho-queue for its Redis user ho: ACL SETUSER ho '~%s*'.", $queuePrefix);

    // The fix follows the failure to the queue's connection, not the storage one.
    expect($results['Storage / Connection']->status->value)->toBe('ok')
        ->and($results['Queues / Redis ho-queue']->status->value)->toBe('error')
        ->and($results['Queues / Redis ho-queue']->message)->toStartWith('answers with an error: ')->toContain('NOPERM')
        ->and($results['Queues / Redis ho-queue']->fix)->toBe($fix)
        ->and($results['Collectors / queues']->status->value)->toBe('error')
        ->and($results['Collectors / queues']->fix)->toBe($fix);

    replyRunRule($redis, $fix);
    $results = replyCheck();

    expect($results['Queues / Redis ho-queue']->status->value)->toBe('ok')
        ->and($results['Collectors / queues']->status->value)->toBe('ok');
})->with(['phpredis', 'predis']);

it('reports an ACL refusal of the job-class index and of the last push as that refusal', function (string $client): void {
    $redis = replyServer($this, $client);
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana:3000');
    config()->set('horizon-observability.live.stream.token', 'glsa_redis-replies-token-0123456789');
    // phpredis answers these refusals with false instead of throwing.
    expect($redis->command('ACL', 'SETUSER', 'ho', '-zrange', '-hgetall'))->toBe('+OK');

    $results = replyCheck();
    $fix = static fn (string $command): string => sprintf(
        'Allow %s for the Redis user ho of database.redis.ho-private: ACL SETUSER ho +%s (the package runs EVAL, TIME and INFO besides the hash, list, sorted set and string commands on its keys).',
        strtoupper($command),
        $command,
    );

    expect($results['Storage / Connection']->status->value)->toBe('ok')
        ->and($results['Job classes / Cap']->status->value)->toBe('error')
        ->and($results['Job classes / Cap']->message)->toContain('NOPERM')
        ->and($results['Job classes / Cap']->fix)->toBe($fix('zrange'))
        ->and($results['Live / Last push']->status->value)->toBe('error')
        ->and($results['Live / Last push']->fix)->toBe($fix('hgetall'));

    replyRunRule($redis, $results['Job classes / Cap']->fix);
    replyRunRule($redis, $results['Live / Last push']->fix);
    $results = replyCheck();

    expect($results['Job classes / Cap']->status->value)->toBe('ok')
        ->and($results['Live / Last push']->message)->toBe('never');
})->with(['phpredis', 'predis']);

it('tells a server at its maxclients to raise it, never that it is reachable', function (string $client): void {
    $redis = replyServer($this, $client);
    // The default user, so the client sends no AUTH: the first command gets the reply.
    config()->set('database.redis.ho-private', ['host' => '127.0.0.1', 'port' => $redis->port, 'database' => 0]);
    // One connection, held here: every other client is refused.
    $held = fsockopen('127.0.0.1', $redis->port, $code, $error, 5.0);
    assert($held !== false);
    stream_set_timeout($held, 10);
    fwrite($held, "*4\r\n$6\r\nCONFIG\r\n$3\r\nSET\r\n$10\r\nmaxclients\r\n$1\r\n1\r\n");
    expect(rtrim((string) fgets($held)))->toBe('+OK');

    try {
        $storage = replyCheck()['Storage / Connection'];
    } finally {
        fwrite($held, "*4\r\n$6\r\nCONFIG\r\n$3\r\nSET\r\n$10\r\nmaxclients\r\n$5\r\n10000\r\n");
        expect(rtrim((string) fgets($held)))->toBe('+OK');
        fclose($held);
    }

    // The server answers the first command and closes the connection. Whether the client reads that reply before it
    // sees the closed connection depends on the timing (predis reads it in its handshake and reports the closed
    // stream): with the reply, the maxclients fix; without, the connectivity one. Never "reachable".
    expect($storage->status->value)->toBe('error');

    if (str_contains($storage->message, 'max number of clients reached')) {
        expect($storage->fix)->toBe('Raise maxclients on the Redis of database.redis.ho-private, or close idle clients (CLIENT LIST shows them): at its limit it refuses every new connection.');
    } else {
        expect($storage->message)->toStartWith('ho-private unreachable: ');
    }
})->with(['phpredis', 'predis']);
