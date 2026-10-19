<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Queues\DelayedJobStore;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use Illuminate\Redis\Connections\Connection;

/*
| Not-yet-due delayed jobs: light rows extracted by delayed_jobs.lua against one `now`, soonest first across queues, the
| class filter, chunked and bounded scans.
*/

pest()->group('redis');

const DELAYED_JOB_STORE_TEST_NOW = 1700000000;

function delayedJobStoreTestRedis(): Connection
{
    return app('redis')->connection('default');
}

/**
 * @param  list<string>|null  $jobClasses
 * @param  list<string>  $queues
 * @return list<array<string, mixed>>
 */
function delayedJobStoreTestRows(?array $jobClasses = null, int $limit = 500, array $queues = ['default', 'slow']): array
{
    return app(DelayedJobStore::class)->rows(
        array_map(static fn (string $queue): array => ['connection' => 'redis', 'queue' => $queue], $queues),
        $jobClasses,
        $limit,
        CarbonImmutable::createFromTimestamp(DELAYED_JOB_STORE_TEST_NOW),
    );
}

/**
 * @return array<string, mixed>
 */
function delayedJobStoreTestJob(string $uuid, string $name = 'App\Jobs\SendReminder', array $overrides = [], array $without = []): array
{
    return QueuePayloads::job($name, ['uuid' => $uuid, 'id' => $uuid, ...$overrides], $without);
}

/**
 * @param  list<float>  $scores
 */
function delayedJobStoreTestFill(string $queue, array $scores, string $name = 'App\Jobs\Filler'): void
{
    foreach (array_chunk($scores, 500, true) as $chunk) {
        $arguments = [];

        foreach ($chunk as $index => $score) {
            $arguments[] = $score;
            $arguments[] = QueuePayloads::encode(delayedJobStoreTestJob('filler-'.$index, $name));
        }

        delayedJobStoreTestRedis()->zadd('queues:'.$queue.':delayed', ...$arguments);
    }
}

it('lists not-yet-due jobs soonest first across queues', function (): void {
    $redis = delayedJobStoreTestRedis();
    $now = (float) DELAYED_JOB_STORE_TEST_NOW;
    QueuePayloads::delay($redis, 'default', delayedJobStoreTestJob('d-late', 'App\Jobs\SendReminder', ['pushedAt' => '1699999990.1234']), $now + 60.5);
    QueuePayloads::delay($redis, 'default', delayedJobStoreTestJob('d-retry', 'App\Jobs\Import', ['attempts' => 2], ['pushedAt']), $now + 30);
    QueuePayloads::delay($redis, 'default', delayedJobStoreTestJob('d-due'), $now - 1);
    QueuePayloads::delay($redis, 'default', delayedJobStoreTestJob('d-now'), $now);
    QueuePayloads::delay($redis, 'slow', delayedJobStoreTestJob('d-slow', 'App\Jobs\Report', ['pushedAt' => 1699999000.5]), $now + 45.25);
    QueuePayloads::push($redis, 'default', delayedJobStoreTestJob('ready'));

    expect(delayedJobStoreTestRows())->toBe([
        ['id' => 'd-retry', 'name' => 'App\Jobs\Import', 'queue' => 'default', 'attempts' => 2, 'pushed_at' => null, 'available_at' => 1700000030.0],
        ['id' => 'd-slow', 'name' => 'App\Jobs\Report', 'queue' => 'slow', 'attempts' => 0, 'pushed_at' => 1699999000.5, 'available_at' => 1700000045.25],
        ['id' => 'd-late', 'name' => 'App\Jobs\SendReminder', 'queue' => 'default', 'attempts' => 0, 'pushed_at' => 1699999990.1234, 'available_at' => 1700000060.5],
    ]);
});

it('extracts the fields of raw payloads like json_decode (shared payload fixtures)', function (): void {
    /** @var array<string, array{payload: string, display_name: string}> $fixtures */
    $fixtures = require dirname(__DIR__, 2).'/Fixtures/payloads.php';
    $expected = [];
    $offset = 0;

    foreach ($fixtures as $case) {
        $decoded = json_decode($case['payload'], true);
        QueuePayloads::delay(delayedJobStoreTestRedis(), 'default', $case['payload'], DELAYED_JOB_STORE_TEST_NOW + 10 + $offset++);
        $expected[] = [
            'id' => (string) ($decoded['uuid'] ?? ''),
            'name' => $case['display_name'],
            'attempts' => (int) ($decoded['attempts'] ?? 0),
            'pushed_at' => isset($decoded['pushedAt']) ? (float) $decoded['pushedAt'] : null,
        ];
    }

    $rows = array_map(
        static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'attempts' => $row['attempts'], 'pushed_at' => $row['pushed_at']],
        delayedJobStoreTestRows(),
    );

    expect($rows)->toBe($expected);
});

it('skips payloads that are not JSON objects but keeps reading after them', function (): void {
    $redis = delayedJobStoreTestRedis();
    QueuePayloads::delay($redis, 'default', 'not json', DELAYED_JOB_STORE_TEST_NOW + 10);
    QueuePayloads::delay($redis, 'default', '["a list"]', DELAYED_JOB_STORE_TEST_NOW + 11);
    QueuePayloads::delay($redis, 'default', delayedJobStoreTestJob('d-1'), DELAYED_JOB_STORE_TEST_NOW + 12);

    expect(array_column(delayedJobStoreTestRows(limit: 1), 'id'))->toBe(['d-1']);
});

it('filters by the normalised job class', function (): void {
    $redis = delayedJobStoreTestRedis();
    QueuePayloads::delay($redis, 'default', delayedJobStoreTestJob('d-closure', 'Closure (routes/console.php:12)'), DELAYED_JOB_STORE_TEST_NOW + 10);
    QueuePayloads::delay($redis, 'default', delayedJobStoreTestJob('d-invoice', 'App\Jobs\SendInvoice'), DELAYED_JOB_STORE_TEST_NOW + 20);

    expect(array_column(delayedJobStoreTestRows(['Closure (routes/console.php)']), 'id'))->toBe(['d-closure'])
        ->and(array_column(delayedJobStoreTestRows(['App\Jobs\SendInvoice', 'App\Jobs\Missing']), 'id'))->toBe(['d-invoice'])
        ->and(delayedJobStoreTestRows(['App\Jobs\Missing']))->toBe([]);
});

it('takes the limit soonest jobs over all queues', function (): void {
    delayedJobStoreTestFill('default', [DELAYED_JOB_STORE_TEST_NOW + 1.0, DELAYED_JOB_STORE_TEST_NOW + 4.0, DELAYED_JOB_STORE_TEST_NOW + 5.0]);
    delayedJobStoreTestFill('slow', [DELAYED_JOB_STORE_TEST_NOW + 2.0, DELAYED_JOB_STORE_TEST_NOW + 3.0, DELAYED_JOB_STORE_TEST_NOW + 6.0]);

    expect(array_column(delayedJobStoreTestRows(limit: 4), 'available_at'))->toBe([1700000001.0, 1700000002.0, 1700000003.0, 1700000004.0])
        ->and(array_column(delayedJobStoreTestRows(limit: 4), 'queue'))->toBe(['default', 'slow', 'slow', 'default']);
});

it('reads no more entries than needed without a class filter', function (): void {
    delayedJobStoreTestFill('default', array_map(static fn (int $index): float => DELAYED_JOB_STORE_TEST_NOW + $index, range(1, 5)));
    $commands = RedisCommands::start($this->app);

    expect(delayedJobStoreTestRows(limit: 2, queues: ['default']))->toHaveCount(2)
        ->and($commands->names())->toBe(['EVAL'])
        ->and(json_encode($commands->commands()[0]['parameters']))->toContain('"2"');
});

it('scans a filtered queue in chunks of 500 entries, at most 10000', function (): void {
    delayedJobStoreTestFill('default', array_map(static fn (int $index): float => DELAYED_JOB_STORE_TEST_NOW + $index, range(1, 1200)));
    QueuePayloads::delay(delayedJobStoreTestRedis(), 'default', delayedJobStoreTestJob('d-rare', 'App\Jobs\Rare'), DELAYED_JOB_STORE_TEST_NOW + 5000);
    $commands = RedisCommands::start($this->app);

    expect(DelayedJobStore::CHUNK)->toBe(500)
        ->and(array_column(delayedJobStoreTestRows(['App\Jobs\Rare'], queues: ['default']), 'id'))->toBe(['d-rare'])
        ->and($commands->names())->toBe(['EVAL', 'EVAL', 'EVAL']);

    delayedJobStoreTestFill('slow', array_map(static fn (int $index): float => DELAYED_JOB_STORE_TEST_NOW + $index, range(1, DelayedJobStore::SCAN_LIMIT)));
    QueuePayloads::delay(delayedJobStoreTestRedis(), 'slow', delayedJobStoreTestJob('d-too-late', 'App\Jobs\Rare'), DELAYED_JOB_STORE_TEST_NOW + 20000);
    $commands->reset();

    expect(delayedJobStoreTestRows(['App\Jobs\Rare'], queues: ['slow']))->toBe([])
        ->and($commands->names())->toBe(array_fill(0, 20, 'EVAL'));
});

it('reads whole chunks after a short first call, at most 20 calls per queue, however small the page', function (): void {
    $redis = delayedJobStoreTestRedis();

    foreach (array_chunk(range(1, DelayedJobStore::SCAN_LIMIT), 1000) as $chunk) {
        $arguments = [];

        foreach ($chunk as $index) {
            $arguments[] = DELAYED_JOB_STORE_TEST_NOW + $index;
            $arguments[] = 'not json '.$index;
        }

        $redis->zadd('queues:slow:delayed', ...$arguments);
    }

    QueuePayloads::delay($redis, 'default', 'not json', DELAYED_JOB_STORE_TEST_NOW + 1);
    QueuePayloads::delay($redis, 'default', 'still not json', DELAYED_JOB_STORE_TEST_NOW + 2);
    QueuePayloads::delay($redis, 'default', delayedJobStoreTestJob('d-1'), DELAYED_JOB_STORE_TEST_NOW + 3);
    $commands = RedisCommands::start($this->app);

    expect(array_column(delayedJobStoreTestRows(limit: 1, queues: ['default']), 'id'))->toBe(['d-1'])
        // 1 entry, then a chunk of 500.
        ->and($commands->names())->toBe(['EVAL', 'EVAL'])
        ->and(delayedJobStoreTestRows(limit: 1, queues: ['slow']))->toBe([])
        ->and($commands->names())->toBe(array_fill(0, 22, 'EVAL'));
});

it('splits large payloads over calls of at most MAX_BYTES and continues after the entries read', function (): void {
    // 60 payloads of about 200 KB (12 MB): one call would block Redis for seconds while it copies them into Lua.
    $command = str_repeat('O:8:"stdClass":1:{s:4:"name";s:5:"value";}', 4600);

    foreach (array_chunk(range(1, 60), 10) as $chunk) {
        $arguments = [];

        foreach ($chunk as $index) {
            $arguments[] = DELAYED_JOB_STORE_TEST_NOW + $index;
            $arguments[] = QueuePayloads::encode(delayedJobStoreTestJob('d-'.$index, 'App\Jobs\Big', ['data' => ['commandName' => 'App\Jobs\Big', 'command' => $command]]));
        }

        delayedJobStoreTestRedis()->zadd('queues:default:delayed', ...$arguments);
    }

    $size = strlen((string) delayedJobStoreTestRedis()->zrange('queues:default:delayed', 0, 0)[0]);
    $commands = RedisCommands::start($this->app);
    $rows = delayedJobStoreTestRows(queues: ['default']);
    $perCall = intdiv(DelayedJobStore::MAX_BYTES, $size) + 1;

    expect($size)->toBeGreaterThan(200_000)
        ->and(array_column($rows, 'id'))->toBe(array_map(static fn (int $index): string => 'd-'.$index, range(1, 60)))
        ->and($perCall)->toBeLessThan(60)
        ->and($commands->names())->toBe(array_fill(0, (int) ceil(60 / $perCall), 'EVAL'));
});

it('skips entries whose score or push time is not a finite number', function (): void {
    $redis = delayedJobStoreTestRedis();
    QueuePayloads::delay($redis, 'default', delayedJobStoreTestJob('d-corrupt-push', overrides: ['pushedAt' => '1e999']), DELAYED_JOB_STORE_TEST_NOW + 10);
    $redis->zadd('queues:default:delayed', '+inf', QueuePayloads::encode(delayedJobStoreTestJob('d-never')));

    expect($redis->zcount('queues:default:delayed', '(1e300', '+inf'))->toBe(1)
        ->and(delayedJobStoreTestRows(queues: ['default']))->toBe([
            ['id' => 'd-corrupt-push', 'name' => 'App\Jobs\SendReminder', 'queue' => 'default', 'attempts' => 0, 'pushed_at' => null, 'available_at' => 1700000010.0],
        ]);
});

it('skips queues whose connection does not use redis, and reads a shared key once', function (): void {
    config()->set('queue.connections.redis-copy', config('queue.connections.redis'));
    QueuePayloads::delay(delayedJobStoreTestRedis(), 'default', delayedJobStoreTestJob('d-1'), DELAYED_JOB_STORE_TEST_NOW + 10);
    $commands = RedisCommands::start($this->app);
    $rows = app(DelayedJobStore::class)->rows([
        ['connection' => 'sync', 'queue' => 'default'],
        ['connection' => 'missing', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'redis-copy', 'queue' => 'default'],
    ], null, 500, CarbonImmutable::createFromTimestamp(DELAYED_JOB_STORE_TEST_NOW));

    expect(array_column($rows, 'id'))->toBe(['d-1'])
        ->and($commands->names())->toBe(['EVAL']);
});

it('skips a queue whose script fails, logs it and lists the other queues', function (): void {
    $this->app->instance('log', $log = new LogRecorder);
    delayedJobStoreTestRedis()->set('queues:broken:delayed', 'not a sorted set');
    QueuePayloads::delay(delayedJobStoreTestRedis(), 'slow', delayedJobStoreTestJob('d-slow'), DELAYED_JOB_STORE_TEST_NOW + 10);

    expect(array_column(delayedJobStoreTestRows(queues: ['broken', 'slow']), 'id'))->toBe(['d-slow'])
        ->and($log->messages('warning'))->toHaveCount(1)
        ->and($log->messages('warning')[0])->toContain('jobs.delayed failed')->toContain('WRONGTYPE');
});

it('passes the request time to Redis, so a job due later in the scan is not skipped', function (): void {
    QueuePayloads::delay(delayedJobStoreTestRedis(), 'default', delayedJobStoreTestJob('d-1'), DELAYED_JOB_STORE_TEST_NOW + 0.5);
    $this->travelTo(CarbonImmutable::createFromTimestamp(DELAYED_JOB_STORE_TEST_NOW + 3600));

    expect(array_column(delayedJobStoreTestRows(), 'id'))->toBe(['d-1'])
        ->and(app(DelayedJobStore::class)->rows([['connection' => 'redis', 'queue' => 'default']], null, 500, CarbonImmutable::createFromTimestamp(DELAYED_JOB_STORE_TEST_NOW + 1)))->toBe([]);
});
