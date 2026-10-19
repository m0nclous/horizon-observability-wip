<?php

declare(strict_types=1);

use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Support\RedisErrorReply;
use Predis\Connection\ConnectionException;
use Predis\Connection\Parameters;
use Predis\Connection\StreamConnection;
use Predis\Response\ServerException;

/*
| The error replies of a Redis that answered, as each client raises them: phpredis throws a
| RedisException (RedisClusterException on a cluster) for every reply but ERR and a few others, predis a
| ServerException, and the package's Lua scripts a RedisScriptFailed whose reason is the reply (a refused redis.call()
| inside the script follows the script's own ERR). The messages are those of Redis 6.2, 7 and 8 and Valkey.
*/

const REPLY_PLACE = 'database.redis.default';

/**
 * The reply as phpredis, predis and a package script raise it.
 *
 * @return array<string, array{0: Throwable}>
 */
function replyRaised(string $message): array
{
    return [
        'phpredis' => [new RedisException($message)],
        'phpredis cluster' => [new RedisClusterException($message)],
        'predis' => [new ServerException($message)],
        'script, predis' => [new RedisScriptFailed('reset_guard', $message, new ServerException($message))],
        'script, phpredis ERR' => [new RedisScriptFailed('new_series', 'ERR TIME failed: '.$message)],
        'script, Redis 6.2' => [new RedisScriptFailed('record_attempt', 'ERR Error running script (call to f_0123456789abcdef): @user_script:12: @user_script: 12: '.$message)],
    ];
}

/**
 * The line of a package script (1-based, as EVAL numbers it) that contains `$code`.
 */
function replyLine(string $script, string $code): int
{
    foreach (file(dirname(__DIR__, 3).'/resources/lua/'.$script.'.lua') ?: [] as $index => $line) {
        if (str_contains($line, $code)) {
            return $index + 1;
        }
    }

    throw new RuntimeException(sprintf('%s.lua has no line with %s.', $script, $code));
}

it('gives a read-only replica the fix to point at the writable primary', function (Throwable $e): void {
    $reply = RedisErrorReply::of($e);

    expect($reply?->code)->toBeIn(['READONLY', 'MASTERDOWN'])
        ->and($reply?->fix(REPLY_PLACE, null, 'app:'))->toBe("Point database.redis.default at the writable primary: it answers as a read-only replica (or a primary a failover demoted), which refuses the package's writes.");
})->with([
    ...replyRaised("READONLY You can't write against a read only replica."),
    'MASTERDOWN' => [new RedisException('MASTERDOWN Link with MASTER is down and replica-serve-stale-data is set to \'no\'.')],
    'a write in a script (Redis 6.2)' => [new RedisScriptFailed('reset_guard', "ERR Error running script (call to f_0123): @user_script:39: @user_script: 39: -READONLY You can't write against a read only replica.")],
]);

it('names the refused command and its ACL rule', function (Throwable $e, string $command, string $user): void {
    $reply = RedisErrorReply::of($e);

    expect($reply?->code)->toBe('NOPERM')
        ->and($reply?->command())->toBe($command)
        ->and($reply?->fix(REPLY_PLACE, 'configured', 'app:'))->toBe(sprintf(
            'Allow %s for the Redis user %s of database.redis.default: ACL SETUSER %s +%s (the package runs EVAL, TIME and INFO besides the hash, list, sorted set and string commands on its keys).',
            strtoupper($command),
            $user,
            $user,
            $command,
        ));
})->with([
    'Redis 7, phpredis' => [new RedisException("NOPERM User ho has no permissions to run the 'eval' command"), 'eval', 'ho'],
    'Redis 7, predis script' => [new RedisScriptFailed('reset_guard', "NOPERM User ho has no permissions to run the 'eval' command", new ServerException("NOPERM User ho has no permissions to run the 'eval' command")), 'eval', 'ho'],
    'Redis 7, TIME in a script' => [new RedisScriptFailed('new_series', "ERR TIME failed: ERR ACL failure in script: User ho has no permissions to run the 'time' command"), 'time', 'ho'],
    'Redis 7, redis.call() in a script' => [new ServerException("ERR ACL failure in script: User ho has no permissions to run the 'hget' command script: 0123456789abcdef, on @user_script:3."), 'hget', 'ho'],
    'Redis 7, a subcommand' => [new ServerException("NOPERM User ho has no permissions to run the 'client|id' command"), 'client|id', 'ho'],
    // Redis 6.2 does not name the user: the one the connection authenticates as.
    'Redis 6.2' => [new RedisScriptFailed('new_series', "ERR TIME failed: NOPERM this user has no permissions to run the 'time' command or its subcommand"), 'time', 'configured'],
    'Redis 6.2, INFO' => [new RedisException("NOPERM this user has no permissions to run the 'info' command or its subcommand"), 'info', 'configured'],
    // Redis 6.2 inside a script names the line: the command the package script runs there.
    'Redis 6.2, TIME on a line of new_series.lua' => [new RedisScriptFailed('new_series', sprintf("ERR TIME failed: @user_script: %d: The user executing the script can't run this command or subcommand", replyLine('new_series', "redis.pcall('TIME')"))), 'time', 'configured'],
    'Redis 6.2, HSET on a line of reset_guard.lua' => [new RedisScriptFailed('reset_guard', sprintf("ERR Error running script (call to f_0123): @user_script:%1\$d: @user_script: %1\$d: The user executing the script can't run this command or subcommand", replyLine('reset_guard', "redis.call('HSET', KEYS[1], 'run_id'"))), 'hset', 'configured'],
]);

it('names the key pattern of a refused key', function (Throwable $e): void {
    $reply = RedisErrorReply::of($e);

    expect($reply?->code)->toBe('NOPERM')
        ->and($reply?->command())->toBeNull()
        ->and($reply?->fix(REPLY_PLACE, null, 'shop_database_'))->toBe("Allow the keys of database.redis.default for its Redis user default: ACL SETUSER default '~shop_database_*'.");
})->with([
    'Redis 7' => [new RedisException('NOPERM No permissions to access a key')],
    'Redis 6.2' => [new ServerException('NOPERM this user has no permissions to access one of the keys used as arguments')],
    'in a script (Redis 6.2)' => [new RedisScriptFailed('record_attempt', 'ERR Error running script (call to f_0123): @user_script:3: @user_script: 3: NOPERM this user has no permissions to access one of the keys used as arguments')],
    'in a script (Redis 7)' => [new ServerException('ERR ACL failure in script: No permissions to access a key script: 0123456789abcdef, on @user_script:3.')],
    'in a script (Redis 6.2, by line)' => [new RedisScriptFailed('record_attempt', "ERR Error running script (call to f_0123): @user_script:3: @user_script: 3: The user executing the script can't access at least one of the keys mentioned in the command")],
]);

it('gives a refused command it cannot name the rules of every command the package needs', function (): void {
    $reply = RedisErrorReply::of(new RedisScriptFailed('not_a_package_script', "ERR x: @user_script: 3: The user executing the script can't run this command or subcommand"));

    expect($reply?->code)->toBe('NOPERM')
        ->and($reply?->command())->toBeNull()
        ->and($reply?->fix(REPLY_PLACE, 'ho', ''))->toBe('Allow the commands of the package for the Redis user ho of database.redis.default: ACL SETUSER ho +@read +@write +@scripting +time +info (Redis refused one without naming it).');
});

it('gives the other replies of a server that answered their fix', function (Throwable $e, string $code, string $fix): void {
    $reply = RedisErrorReply::of($e);

    expect($reply?->code)->toBe($code)
        ->and($reply?->fix(REPLY_PLACE, null, ''))->toBe($fix);
})->with([
    'NOAUTH' => [new RedisException('NOAUTH Authentication required.'), 'NOAUTH', 'Set the password (and username) Redis expects in database.redis.default (REDIS_PASSWORD, REDIS_USERNAME).'],
    'WRONGPASS' => [new ServerException('WRONGPASS invalid username-password pair or user is disabled.'), 'WRONGPASS', 'Set the password (and username) Redis expects in database.redis.default (REDIS_PASSWORD, REDIS_USERNAME).'],
    'a password for a user without one (predis 3)' => [new ConnectionException(new StreamConnection(new Parameters), 'Failed: ERR AUTH <password> called without any password configured for the default user. Are you sure your configuration is correct?'), 'WRONGPASS', 'Set the password (and username) Redis expects in database.redis.default (REDIS_PASSWORD, REDIS_USERNAME).'],
    'OOM' => [new RedisException("OOM command not allowed when used memory > 'maxmemory'."), 'OOM', "Free memory on the Redis of database.redis.default or raise its maxmemory: at maxmemory it refuses the package's writes (OOM)."],
    'OOM in a script (Redis 6.2)' => [new RedisScriptFailed('reset_guard', "ERR Error running script (call to f_0123): @user_script:39: @user_script: 39: -OOM command not allowed when used memory > 'maxmemory'."), 'OOM', "Free memory on the Redis of database.redis.default or raise its maxmemory: at maxmemory it refuses the package's writes (OOM)."],
    'MISCONF' => [new ServerException('MISCONF Redis is configured to save RDB snapshots, but it\'s currently unable to persist to disk.'), 'MISCONF', 'Fix the failing RDB snapshots of the Redis of database.redis.default (disk space or permissions of its dir, see its log): until then it refuses writes (MISCONF).'],
    'LOADING' => [new RedisException('LOADING Redis is loading the dataset in memory'), 'LOADING', 'Wait until the Redis of database.redis.default has loaded its dataset after its restart, then run the check again.'],
    'BUSY' => [new ServerException('BUSY Redis is busy running a script. You can only call SCRIPT KILL or SHUTDOWN NOSAVE.'), 'BUSY', 'A script runs too long on the Redis of database.redis.default (BUSY): wait for it or stop it with SCRIPT KILL, then run the check again.'],
    'WRONGTYPE' => [new ServerException('WRONGTYPE Operation against a key holding the wrong kind of value'), 'WRONGTYPE', 'A key it reads on database.redis.default holds another type, so another app writes the same key names: give each app its own prefix in config/database.php.'],
    'CROSSSLOT' => [new RedisClusterException("CROSSSLOT Keys in request don't hash to the same slot"), 'CROSSSLOT', 'Give the prefix of database.redis.default a hash tag with content, e.g. {app}: the keys of one command are in different slots of the cluster (see Storage, Prefix).'],
    'unknown command (Redis 7)' => [new RedisScriptFailed('reset_guard', "ERR unknown command 'EVAL', with args beginning with: "), 'UNKNOWN', 'The Redis of database.redis.default does not offer eval (a proxy or a managed Redis that disables it): point the connection at a Redis that runs EVAL, TIME and INFO, which the package needs.'],
    'unknown command (Redis 6.2)' => [new ServerException('ERR unknown command `TIME`, with args beginning with: '), 'UNKNOWN', 'The Redis of database.redis.default does not offer time (a proxy or a managed Redis that disables it): point the connection at a Redis that runs EVAL, TIME and INFO, which the package needs.'],
]);

it('finds no reply in a connection failure or outside Redis', function (Throwable $e): void {
    expect(RedisErrorReply::of($e))->toBeNull();
})->with([
    'read error' => [new RedisException('read error on connection to 127.0.0.1:6379')],
    'refused' => [new RedisException('Connection refused')],
    'went away' => [new RedisClusterException("Couldn't map cluster keyspace using any provided seed")],
    'a timeout of the script' => [new RedisScriptFailed('reset_guard', 'read error on connection')],
    'not from Redis' => [new RuntimeException('READONLY You can\'t write against a read only replica.')],
    'predis, refused' => [new ConnectionException(new StreamConnection(new Parameters), 'Connection refused [tcp://127.0.0.1:1]')],
    'a word that only looks like one' => [new ServerException('ERR BROOM is not a command; NOPERMISSION')],
]);

it('reads the reply of the cause', function (): void {
    $e = new RuntimeException('the batch links failed', previous: new ServerException("READONLY You can't write against a read only replica."));

    expect(RedisErrorReply::of($e)?->code)->toBe('READONLY');
});
