<?php

declare(strict_types=1);

use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use Illuminate\Redis\Connections\Connection;

/*
| The contracts of job_details.lua and delayed_jobs.lua (documented in their headers) on both clients: KEYS, ARGV and
| the reply shapes. Nil replies are false on phpredis and null on predis; the helpers read both as null.
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

it('returns the read count, the more flag and light rows of not-yet-due entries (delayed_jobs.lua)', function (): void {
    $redis = app('redis')->connection('default');
    QueuePayloads::delay($redis, 'default', '{"displayName":"App\\\\Jobs\\\\A","uuid":"u-1","attempts":1,"pushedAt":"1699999999.5"}', 1700000010.25);
    QueuePayloads::delay($redis, 'default', 'garbage', 1700000020.0);
    QueuePayloads::delay($redis, 'default', '{"uuid":"u-3","pushedAt":1699999998.75,"attempts":null}', 1700000030.0);
    QueuePayloads::delay($redis, 'default', '{"uuid":"u-due"}', 1700000000.0);
    $reader = app(RedisClients::class)->reader('default');

    expect(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 0, 500]))->toBe([
        3,
        0,
        ['u-1', 'App\\\\Jobs\\\\A', 1, '1699999999.5', '1700000010.25'],
        ['u-3', '', 0, '1699999998.75', '1700000030'],
    ])->and(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 1, 1]))->toBe([1, 1])
        ->and(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 2, 5]))->toBe([
            1,
            0,
            ['u-3', '', 0, '1699999998.75', '1700000030'],
        ])->and(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 0, 3]))->toHaveCount(4)
        ->and(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 0, 3])[1])->toBe(1)
        ->and(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000030.0, 0, 5]))->toBe([0, 0])
        ->and(luaScriptsTestEval($reader, 'delayed_jobs', ['queues:missing:delayed'], [1700000000.0, 0, 5]))->toBe([0, 0]);
});

it('reads in batches across the count and stops after the entry that reaches the byte budget (delayed_jobs.lua)', function (): void {
    $redis = app('redis')->connection('default');

    foreach (range(1, 60) as $index) {
        QueuePayloads::delay($redis, 'default', QueuePayloads::encode(QueuePayloads::job('App\Jobs\Big', ['uuid' => 'u-'.$index, 'data' => ['command' => str_repeat('x', 1000)]])), 1700000000.0 + $index);
    }

    $reader = app(RedisClients::class)->reader('default');
    $all = luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 0, 500, 0]);
    $size = strlen((string) $redis->zrange('queues:default:delayed', 0, 0)[0]);
    $capped = luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 0, 500, 2 * $size + 1]);
    $next = luaScriptsTestEval($reader, 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 3, 500, 1]);

    expect(array_slice($all, 0, 2))->toBe([60, 0])
        ->and(array_column(array_slice($all, 2), 0))->toBe(array_map(static fn (int $index): string => 'u-'.$index, range(1, 60)))
        // Three entries reach 2 * size + 1 bytes: the third is read, the call stops after it.
        ->and(array_slice($capped, 0, 2))->toBe([3, 1])
        ->and(array_column(array_slice($capped, 2), 0))->toBe(['u-1', 'u-2', 'u-3'])
        // A budget below one payload still reads one entry.
        ->and(array_slice($next, 0, 2))->toBe([1, 1])
        ->and(array_column(array_slice($next, 2), 0))->toBe(['u-4']);
});

it('finds the fields of a large payload in Laravel\'s order, in cjson\'s and in the middle (delayed_jobs.lua)', function (): void {
    $redis = app('redis')->connection('default');
    $big = str_repeat('O:8:"stdClass":1:{s:4:"name";s:5:"value";}', 400);
    $fresh = QueuePayloads::encode(QueuePayloads::job('App\Jobs\Big', ['uuid' => 'u-fresh', 'attempts' => 2, 'pushedAt' => '1699999999.5', 'data' => ['command' => $big]]));
    $reordered = (string) $redis->eval('return cjson.encode(cjson.decode(ARGV[1]))', 0, str_replace('u-fresh', 'u-cjson', $fresh));
    $middle = '{"data":{"command":"'.str_repeat('x', 9000).'"},"uuid":"u-middle","displayName":"App\\\\Jobs\\\\Big","attempts":4,"pushedAt":"1699999998","tail":"'.str_repeat('y', 9000).'"}';

    // cjson writes the keys in the order of the server's Lua tables (Redis 7.4 puts "data" before "uuid", Valkey 8
    // does not); $middle has every identifier outside both 4096-byte windows on every server, so the whole-payload
    // fallback of locate() is exercised wherever $reordered happens to keep Laravel's order.
    expect(strlen($fresh))->toBeGreaterThan(2 * 4096)
        ->and(json_decode($reordered, true))->toEqual(json_decode(str_replace('u-fresh', 'u-cjson', $fresh), true));

    foreach (['"uuid":', '"displayName":', '"attempts":', '"pushedAt":'] as $needle) {
        expect(strpos($middle, $needle))->toBeGreaterThan(4096)
            ->and(strlen($middle) - (int) strpos($middle, $needle))->toBeGreaterThan(4096);
    }

    QueuePayloads::delay($redis, 'default', $fresh, 1700000001.0);
    QueuePayloads::delay($redis, 'default', $reordered, 1700000002.0);
    QueuePayloads::delay($redis, 'default', $middle, 1700000003.0);

    expect(array_slice(luaScriptsTestEval(app(RedisClients::class)->reader('default'), 'delayed_jobs', ['queues:default:delayed'], [1700000000.0, 0, 500]), 2))->toBe([
        ['u-fresh', 'App\\\\Jobs\\\\Big', 2, '1699999999.5', '1700000001'],
        ['u-cjson', 'App\\\\Jobs\\\\Big', 2, '1699999999.5', '1700000002'],
        ['u-middle', 'App\\\\Jobs\\\\Big', 4, '1699999998', '1700000003'],
    ]);
});

it('rejects a non-numeric now, offset, count or byte budget (delayed_jobs.lua)', function (array $args): void {
    app(Lua::class)->eval(app(RedisClients::class)->reader('default'), 'delayed_jobs', ['queues:default:delayed'], $args);
})->throws(RedisScriptFailed::class, 'now, offset, count and max_bytes must be numbers')->with([
    'now' => [['soon', 0, 5]],
    'max_bytes' => [[1700000000.0, 0, 5, 'many']],
]);
