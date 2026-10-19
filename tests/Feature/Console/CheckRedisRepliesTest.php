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
| horizon-observability:check on a storage Redis that answers but refuses (T14 QA round 3): a read-only replica (a
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
    // The new-series hold reads the storage Redis' clock (T16); Redis 7+ and Valkey answer "ERR ACL failure in
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
