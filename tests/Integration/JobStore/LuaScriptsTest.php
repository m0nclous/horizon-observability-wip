<?php

declare(strict_types=1);

use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use Illuminate\Redis\Connections\Connection;

/*
| The contracts of job_details.lua and delayed_jobs.lua (Arch Appendix D) on both clients: KEYS, ARGV and the
| reply shapes. Nil replies are false on phpredis and null on predis; the helpers read both as null.
*/

pest()->group('redis');

function luaScriptsTestHorizon(): Connection
{
    return app(RedisClients::class)->reader('horizon');
}

/**
 * The reply with false (phpredis nil) read as null, recursively.
 */
function luaScriptsTestNormalize(mixed $reply): mixed
{
    if (is_array($reply)) {
        return array_map(luaScriptsTestNormalize(...), array_values($reply));
    }

    return $reply === false ? null : $reply;
}

/**
 * @param  list<string>  $keys
 * @param  list<string|int|float>  $args
 */
function luaScriptsTestEval(Connection $redis, string $script, array $keys, array $args): mixed
{
    return luaScriptsTestNormalize(app(Lua::class)->eval($redis, $script, $keys, $args));
}

it('returns the attempts and the first exception line per job hash, in KEYS order (job_details.lua)', function (): void {
    $redis = app('redis')->connection('horizon');
    $redis->hmset('job-a', ['payload' => QueuePayloads::encode(QueuePayloads::job(overrides: ['attempts' => 3])), 'exception' => "RuntimeException: Boom in /app/x.php:3\nStack trace:\n#0 {main}"]);
    $redis->hmset('job-b', ['payload' => QueuePayloads::encode(QueuePayloads::job(overrides: ['attempts' => '7']))]);
    $redis->hmset('job-c', ['exception' => "Foo@anonymous\0/app/x.php:3\$0: no newline"]);

    expect(luaScriptsTestEval(luaScriptsTestHorizon(), 'job_details', ['job-a', 'job-missing', 'job-b', 'job-c'], [1000]))->toBe([
        [3, 'RuntimeException: Boom in /app/x.php:3'],
        [null, null],
        [7, null],
        [null, "Foo@anonymous\0/app/x.php:3\$0: no newline"],
    ]);
});

it('cuts the exception line at the byte cap and skips it with a cap of 0 (job_details.lua)', function (): void {
    app('redis')->connection('horizon')->hmset('job-a', ['payload' => '{"attempts":2}', 'exception' => 'RuntimeException: '.str_repeat('Ж', 10)."\nStack trace:"]);

    expect(luaScriptsTestEval(luaScriptsTestHorizon(), 'job_details', ['job-a'], [21]))->toBe([[2, "RuntimeException: \xD0\x96\xD0"]])
        ->and(luaScriptsTestEval(luaScriptsTestHorizon(), 'job_details', ['job-a'], [0]))->toBe([[2, null]]);
});

it('reads the top-level attempts of every payload fixture (job_details.lua)', function (): void {
    /** @var array<string, array{payload: string}> $fixtures */
    $fixtures = require dirname(__DIR__, 2).'/Fixtures/payloads.php';
    $keys = [];
    $expected = [];

    foreach (array_values($fixtures) as $index => $case) {
        app('redis')->connection('horizon')->hset('job-'.$index, 'payload', $case['payload']);
        $keys[] = 'job-'.$index;
        $attempts = json_decode($case['payload'], true)['attempts'] ?? null;
        $expected[] = [is_int($attempts) ? $attempts : null, null];
    }

    expect(luaScriptsTestEval(luaScriptsTestHorizon(), 'job_details', $keys, [1000]))->toBe($expected);
});

it('rejects a non-numeric byte cap (job_details.lua)', function (): void {
    app(Lua::class)->eval(luaScriptsTestHorizon(), 'job_details', ['job-a'], ['many']);
})->throws(RedisScriptFailed::class, 'max_exception_bytes must be a number');

it('returns the read count and light rows of not-yet-due entries (delayed_jobs.lua)', function (): void {
    $redis = app('redis')->connection('default');
    QueuePayloads::delay($redis, 'default', '{"displayName":"App\\\\Jobs\\\\A","uuid":"u-1","attempts":1,"pushedAt":"1699999999.5"}', 1700000010.25);
    QueuePayloads::delay($redis, 'default', 'garbage', 1700000020.0);
    QueuePayloads::delay($redis, 'default', '{"uuid":"u-3","pushedAt":1699999998.75,"attempts":null}', 1700000030.0);
    QueuePayloads::delay($redis, 'default', '{"uuid":"u-due"}', 1700000000.0);
    $reader = app(RedisClients::class)->reader('default');

    expect(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 0, 500]))->toBe([
        3,
        ['u-1', 'App\\\\Jobs\\\\A', 1, '1699999999.5', '1700000010.25'],
        ['u-3', '', 0, '1699999998.75', '1700000030'],
    ])->and(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 1, 1]))->toBe([1])
        ->and(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 2, 5]))->toBe([
            1,
            ['u-3', '', 0, '1699999998.75', '1700000030'],
        ])->and(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000030.0, 0, 5]))->toBe([0])
        ->and(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:missing:delayed'], [1700000000.0, 0, 5]))->toBe([0]);
});

it('rejects a non-numeric now, offset or count (delayed_jobs.lua)', function (): void {
    app(Lua::class)->eval(app(RedisClients::class)->reader('default'), 'delayed_jobs', ['queues:default:delayed'], ['soon', 0, 5]);
})->throws(RedisScriptFailed::class, 'now, offset and count must be numbers');
