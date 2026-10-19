<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Metrics\Labels;
use DF\HorizonObservability\Queues\QueueInspector;
use DF\HorizonObservability\Queues\QueueSnapshot;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Redis\Connections\Connection;

/*
| queue_state.lua (its contract is in the script header) through QueueInspector: counts, due delayed jobs, the readiness
| of the first fresh head payload, the class breakdown, the scan and byte caps, and the live mode. Runs on phpredis and
| on predis (REDIS_CLIENT=predis).
*/

pest()->group('redis');

const QUEUE_STATE_NOW = 1700001000.5;

function queueStateNow(): CarbonImmutable
{
    return CarbonImmutable::createFromTimestamp(QUEUE_STATE_NOW);
}

function queueStateRedis(): Connection
{
    return app('redis')->connection('default');
}

function queueStateInspect(string $queue = 'default'): QueueSnapshot
{
    return app(QueueInspector::class)->inspect('redis', $queue, queueStateNow());
}

/**
 * A payload of a job dispatched at $dispatchedAt (Unix seconds), as Horizon writes it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function queueStateJob(float $dispatchedAt, array $overrides = [], string $class = 'App\\Jobs\\SendInvoice'): array
{
    return QueuePayloads::job($class, [
        'createdAt' => (int) floor($dispatchedAt),
        'pushedAt' => sprintf('%.4F', $dispatchedAt),
        ...$overrides,
    ]);
}

function queueStateScanLimit(int $limit): void
{
    config()->set('horizon-observability.queue_scan_limit', $limit);
    app()->forgetInstance(Config::class);
}

it('reports an empty queue as zero, with a zero age', function (): void {
    $snapshot = queueStateInspect();

    expect($snapshot)->toEqual(new QueueSnapshot('redis', 'default', 0, 0, 0, 0, null, null, 0))
        ->and($snapshot->oldestAgeSeconds(QUEUE_STATE_NOW))->toBe(0.0);
});

it('counts ready, reserved and delayed jobs, with due delayed jobs as pending', function (): void {
    $redis = queueStateRedis();

    foreach (range(1, 3) as $i) {
        QueuePayloads::push($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 10));
    }

    QueuePayloads::reserve($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 20), QUEUE_STATE_NOW + 60);
    QueuePayloads::reserve($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 20), QUEUE_STATE_NOW + 60);
    QueuePayloads::delay($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 90), QUEUE_STATE_NOW - 30.25);
    QueuePayloads::delay($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 90), QUEUE_STATE_NOW);
    QueuePayloads::delay($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 90), QUEUE_STATE_NOW + 0.5);

    $snapshot = queueStateInspect();

    expect($snapshot->length)->toBe(3)
        ->and($snapshot->reserved)->toBe(2)
        ->and($snapshot->delayed)->toBe(3)
        ->and($snapshot->delayedDue)->toBe(2)
        ->and($snapshot->pending())->toBe(5)
        ->and($snapshot->delayedNotDue())->toBe(1)
        ->and($snapshot->earliestDueAt)->toBe(QUEUE_STATE_NOW - 30.25)
        ->and($snapshot->firstFreshReadyAt)->toBe((float) sprintf('%.4F', QUEUE_STATE_NOW - 10))
        // The due delayed job waits longer than the head: the age counts from its due time.
        ->and($snapshot->oldestAgeSeconds(QUEUE_STATE_NOW))->toBe(30.25)
        ->and($snapshot->pendingByClass)->toBe(['App\\Jobs\\SendInvoice' => 3])
        ->and($snapshot->reservedByClass)->toBe(['App\\Jobs\\SendInvoice' => 2])
        ->and($snapshot->unscannedPending())->toBe(0)
        ->and($snapshot->unscannedReserved())->toBe(0);
});

test('delayed, retried and released heads', function (): void {
    // The pilot aged the queue from the head's pushedAt (or createdAt), so a head that had been delayed, released or
    // retried reported its delay, backoff and earlier runtimes as wait, and the long-wait alert fired. Readiness is now
    // max(pushedAt, createdAt + delay) of the first payload with attempts == 0, or the earliest due delayed score.
    $redis = queueStateRedis();
    $now = QUEUE_STATE_NOW;

    // A job dispatched 700 s ago with ->delay(600), migrated to the list when due: it has waited 100 s, not 700 s.
    QueuePayloads::push($redis, 'delayed', queueStateJob($now - 700, ['delay' => 600]));
    $delayed = queueStateInspect('delayed');

    // A released job (attempts 2, pushed 900 s ago) at the head is skipped; the fresh job behind it waited 40 s.
    QueuePayloads::push($redis, 'released', queueStateJob($now - 900, ['attempts' => 2]));
    QueuePayloads::push($redis, 'released', queueStateJob($now - 40));
    $released = queueStateInspect('released');

    // A Horizon retry of a job dispatched 2 h ago with ->delay(600) keeps createdAt and delay but gets attempts 0 and a
    // fresh pushedAt: it has waited 5 s.
    QueuePayloads::push($redis, 'retried', queueStateJob($now - 7200, ['delay' => 600, 'pushedAt' => sprintf('%.4F', $now - 5), 'retry_of' => 'f00']));
    $retried = queueStateInspect('retried');

    // Only released jobs and nothing due: the age is unknown and the series is omitted.
    QueuePayloads::push($redis, 'stale', queueStateJob($now - 900, ['attempts' => 1]));
    $stale = queueStateInspect('stale');

    // Only released jobs, but a delayed job became due 12 s ago: the age counts from its due time.
    QueuePayloads::push($redis, 'due', queueStateJob($now - 900, ['attempts' => 1]));
    QueuePayloads::delay($redis, 'due', queueStateJob($now - 300, ['attempts' => 1]), $now - 12);
    $due = queueStateInspect('due');

    expect($delayed->oldestAgeSeconds($now))->toBe(100.5)
        ->and($released->oldestAgeSeconds($now))->toBeGreaterThanOrEqual(40.0)->toBeLessThan(40.001)
        ->and($released->scanned)->toBe(2)
        ->and($retried->oldestAgeSeconds($now))->toBeGreaterThanOrEqual(5.0)->toBeLessThan(5.001)
        ->and($stale->firstFreshReadyAt)->toBeNull()
        ->and($stale->oldestAgeSeconds($now))->toBeNull()
        ->and($due->firstFreshReadyAt)->toBeNull()
        ->and($due->oldestAgeSeconds($now))->toBe(12.0);
});

it('reads the payloads Laravel and Horizon write, including a released one', function (): void {
    $queue = app('queue')->connection('redis');
    $inspect = static fn (): QueueSnapshot => app(QueueInspector::class)->inspect('redis', 'real', CarbonImmutable::now()->addSecond());
    $queue->push('App\\Jobs\\SendInvoice@handle', ['invoice' => 42], 'real');
    $queue->later(60, 'App\\Jobs\\SendReminder@handle', ['to' => 'a@example.com'], 'real');
    $pushed = $inspect();

    // A worker pops the job (cjson rewrites it into :reserved with attempts 1) and releases it without a delay.
    $job = $queue->pop('real');
    expect($job)->not->toBeNull();
    $popped = $inspect();
    $job?->release();
    $released = $inspect();
    $queue->migrateExpiredJobs('queues:real:delayed', 'queues:real');
    $migrated = $inspect();

    expect($pushed->length)->toBe(1)
        ->and($pushed->pendingByClass)->toBe(['App\\Jobs\\SendInvoice' => 1])
        ->and($pushed->firstFreshReadyAt)->not->toBeNull()
        ->and($pushed->delayed)->toBe(1)
        ->and($pushed->delayedDue)->toBe(0)
        ->and($popped->length)->toBe(0)
        ->and($popped->reserved)->toBe(1)
        ->and($popped->reservedByClass)->toBe(['App\\Jobs\\SendInvoice' => 1])
        // The released job waits in :delayed until a worker of the queue migrates it.
        ->and($released->reserved)->toBe(0)
        ->and($released->delayed)->toBe(2)
        ->and($released->delayedDue)->toBe(1)
        ->and($released->pending())->toBe(1)
        ->and($released->earliestDueAt)->not->toBeNull()
        // Migrated: the head is a released attempt (attempts 1), so it gives no readiness.
        ->and($migrated->length)->toBe(1)
        ->and($migrated->delayedDue)->toBe(0)
        ->and($migrated->pendingByClass)->toBe(['App\\Jobs\\SendInvoice' => 1])
        ->and($migrated->firstFreshReadyAt)->toBeNull()
        ->and($migrated->oldestAgeSeconds((float) CarbonImmutable::now()->format('U.u')))->toBeNull();
});

it('counts the scanned head by normalised class and leaves the rest unscanned', function (): void {
    queueStateScanLimit(5);
    $redis = queueStateRedis();
    $now = QUEUE_STATE_NOW;

    QueuePayloads::push($redis, 'default', queueStateJob($now - 9, class: 'Closure (routes/web.php:42)'));
    QueuePayloads::push($redis, 'default', queueStateJob($now - 8, class: 'Closure (routes/web.php:7)'));
    QueuePayloads::push($redis, 'default', queueStateJob($now - 7, class: ''));
    QueuePayloads::push($redis, 'default', queueStateJob($now - 6, class: 'App\\Jobs\\Weird "quoted"'));
    QueuePayloads::push($redis, 'default', (string) json_encode(queueStateJob($now - 5, class: "App\\Jobs\\\u{041E}\u{0442}\u{0447}\u{0451}\u{0442}")));
    QueuePayloads::push($redis, 'default', queueStateJob($now - 4));
    QueuePayloads::push($redis, 'default', queueStateJob($now - 3));

    $snapshot = queueStateInspect();

    expect($snapshot->pendingByClass)->toEqualCanonicalizing([
        'Closure (routes/web.php)' => 2,
        Labels::UNKNOWN => 1,
        'App\\Jobs\\Weird "quoted"' => 1,
        "App\\Jobs\\\u{041E}\u{0442}\u{0447}\u{0451}\u{0442}" => 1,
    ])->and($snapshot->scanned)->toBe(5)
        ->and($snapshot->length)->toBe(7)
        ->and($snapshot->unscannedPending())->toBe(2);
});

it('reads only the head for the readiness with a scan limit of 0', function (): void {
    queueStateScanLimit(0);
    $redis = queueStateRedis();
    QueuePayloads::push($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 30));
    QueuePayloads::push($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 20));

    $snapshot = queueStateInspect();

    expect($snapshot->pendingByClass)->toBe([])
        ->and($snapshot->scanned)->toBe(1)
        ->and($snapshot->unscannedPending())->toBe(2)
        ->and($snapshot->oldestAgeSeconds(QUEUE_STATE_NOW))->toBeGreaterThanOrEqual(30.0)->toBeLessThan(30.001);
});

it('finds the first fresh payload anywhere in the scan window in full mode', function (): void {
    $redis = queueStateRedis();

    foreach (range(1, 30) as $i) {
        QueuePayloads::push($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 500, ['attempts' => 1]));
    }

    QueuePayloads::push($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 50));

    $snapshot = queueStateInspect();

    expect($snapshot->scanned)->toBe(31)
        ->and($snapshot->oldestAgeSeconds(QUEUE_STATE_NOW))->toBeGreaterThanOrEqual(50.0)->toBeLessThan(50.001);
});

it('stops the head scan at the byte cap', function (): void {
    // The script contract with a small cap: the payload that reaches the cap is the last one read.
    $redis = queueStateRedis();
    $payload = QueuePayloads::encode(queueStateJob(QUEUE_STATE_NOW - 10, ['attempts' => 1, 'tags' => [str_repeat('x', 300)]]));

    foreach (range(1, 10) as $i) {
        QueuePayloads::push($redis, 'default', $payload);
    }

    $reader = app(RedisClients::class)->reader('default');
    $reply = app(Lua::class)->eval($reader, 'queue_state', ['queues:default', 'queues:default:reserved', 'queues:default:delayed'], [QUEUE_STATE_NOW, 1000, 1000, 3 * strlen($payload) - 1, 'full']);

    expect($reply)->toBeArray()
        ->and($reply[6])->toBe(3)
        ->and($reply[7])->toBe(['App\\\\Jobs\\\\SendInvoice', 3]);
});

it('stops the head scan at 16 MB in full mode', function (): void {
    $redis = queueStateRedis();
    $payload = QueuePayloads::encode(queueStateJob(QUEUE_STATE_NOW - 10, ['attempts' => 1, 'tags' => [str_repeat('x', 3 * 1024 * 1024)]]));

    foreach (range(1, 7) as $i) {
        QueuePayloads::push($redis, 'default', $payload);
    }

    $snapshot = queueStateInspect();

    // 3 MB payloads: the sixth one passes 16 MB, the seventh is never read.
    expect($snapshot->scanned)->toBe(6)
        ->and($snapshot->pendingByClass)->toBe(['App\\Jobs\\SendInvoice' => 6])
        ->and($snapshot->unscannedPending())->toBe(1);
});

it('scans at most 20 head payloads and 1 MB in live mode', function (): void {
    $redis = queueStateRedis();
    $inspector = app(QueueInspector::class);

    foreach (range(1, 25) as $i) {
        QueuePayloads::push($redis, 'many', queueStateJob(QUEUE_STATE_NOW - 500, ['attempts' => 1]));
    }

    QueuePayloads::push($redis, 'many', queueStateJob(QUEUE_STATE_NOW - 50));

    $large = queueStateJob(QUEUE_STATE_NOW - 500, ['attempts' => 1, 'tags' => [str_repeat('x', 300 * 1024)]]);

    foreach (range(1, 5) as $i) {
        QueuePayloads::push($redis, 'large', $large);
    }

    QueuePayloads::push($redis, 'large', queueStateJob(QUEUE_STATE_NOW - 50));

    $many = $inspector->live('redis', 'many', queueStateNow(), false);
    $largeLive = $inspector->live('redis', 'large', queueStateNow(), false);

    expect($many->scanned)->toBe(20)
        ->and($many->firstFreshReadyAt)->toBeNull()
        ->and($many->pendingByClass)->toBe([])
        ->and($many->length)->toBe(26)
        // 300 KB payloads: the fourth one passes 1 MB.
        ->and($largeLive->scanned)->toBe(4)
        ->and($largeLive->firstFreshReadyAt)->toBeNull()
        // The full mode reads on and finds both fresh jobs.
        ->and(queueStateInspect('many')->firstFreshReadyAt)->not->toBeNull()
        ->and(queueStateInspect('large')->firstFreshReadyAt)->not->toBeNull();
});

it('stops the live scan at the first fresh payload and honours the scan limit', function (): void {
    $redis = queueStateRedis();

    foreach (range(1, 5) as $i) {
        QueuePayloads::push($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 60 + $i));
    }

    $live = app(QueueInspector::class)->live('redis', 'default', queueStateNow(), false);
    queueStateScanLimit(3);
    QueuePayloads::push($redis, 'limited', queueStateJob(QUEUE_STATE_NOW - 500, ['attempts' => 1]));
    QueuePayloads::push($redis, 'limited', queueStateJob(QUEUE_STATE_NOW - 500, ['attempts' => 1]));
    QueuePayloads::push($redis, 'limited', queueStateJob(QUEUE_STATE_NOW - 500, ['attempts' => 1]));
    QueuePayloads::push($redis, 'limited', queueStateJob(QUEUE_STATE_NOW - 50));
    $limited = app(QueueInspector::class)->live('redis', 'limited', queueStateNow(), false);

    expect($live->scanned)->toBe(1)
        ->and($live->oldestAgeSeconds(QUEUE_STATE_NOW))->toBeGreaterThanOrEqual(59.0)->toBeLessThan(59.001)
        ->and($limited->scanned)->toBe(3)
        ->and($limited->firstFreshReadyAt)->toBeNull();
});

it('returns reserved classes in live mode only on request', function (): void {
    $redis = queueStateRedis();
    QueuePayloads::reserve($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 5), QUEUE_STATE_NOW + 60);
    QueuePayloads::reserve($redis, 'default', queueStateJob(QUEUE_STATE_NOW - 5, class: 'App\\Jobs\\Export'), QUEUE_STATE_NOW + 60);
    $inspector = app(QueueInspector::class);

    $without = $inspector->live('redis', 'default', queueStateNow(), false);
    $with = $inspector->live('redis', 'default', queueStateNow(), true);

    expect($without->reserved)->toBe(2)
        ->and($without->reservedByClass)->toBe([])
        ->and($without->unscannedReserved())->toBe(2)
        ->and($with->reserved)->toBe(2)
        ->and($with->reservedByClass)->toEqualCanonicalizing(['App\\Jobs\\SendInvoice' => 1, 'App\\Jobs\\Export' => 1]);
});

it('counts at most 1000 reserved payloads by class', function (): void {
    $prefix = TestCase::redisPrefix();
    $members = [];

    foreach (range(1, 1005) as $i) {
        $members[QueuePayloads::encode(queueStateJob(QUEUE_STATE_NOW - 5))] = QUEUE_STATE_NOW + $i;
    }

    RedisKeys::client()->zadd($prefix.'queues:default:reserved', $members);

    $snapshot = queueStateInspect();

    expect($snapshot->reserved)->toBe(1005)
        ->and($snapshot->reservedByClass)->toBe(['App\\Jobs\\SendInvoice' => QueueInspector::RESERVED_SCAN_LIMIT])
        ->and($snapshot->unscannedReserved())->toBe(5);
});

it('stops the reserved scan at 16 MB in both modes', function (): void {
    // The reserved scan is capped at 1000 members and, like the head scan, by bytes: 1000 reserved payloads of a few
    // MB each would otherwise be copied on every scrape.
    $prefix = TestCase::redisPrefix();
    $members = [];

    foreach (range(1, 7) as $i) {
        $members[QueuePayloads::encode(queueStateJob(QUEUE_STATE_NOW - 5, ['tags' => [str_repeat('x', 3 * 1024 * 1024)]]))] = QUEUE_STATE_NOW + $i;
    }

    RedisKeys::client()->zadd($prefix.'queues:default:reserved', $members);

    $full = queueStateInspect();
    $live = app(QueueInspector::class)->live('redis', 'default', queueStateNow(), true);

    // 3 MB payloads: the sixth one passes 16 MB, the seventh is never read.
    expect($full->reserved)->toBe(7)
        ->and($full->reservedByClass)->toBe(['App\\Jobs\\SendInvoice' => 6])
        ->and($full->unscannedReserved())->toBe(1)
        ->and($live->reservedByClass)->toBe(['App\\Jobs\\SendInvoice' => 6]);
});

it('reads the queues of another queue connection through its own Redis connection and prefix', function (): void {
    config()->set('database.redis.queues', [...config('database.redis.default'), 'options' => ['prefix' => TestCase::redisPrefix().'other:']]);
    config()->set('queue.connections.other', ['driver' => 'redis', 'connection' => 'queues', 'queue' => 'default', 'retry_after' => 90]);
    app()->forgetInstance(RedisClients::class);
    RedisKeys::client()->rpush(TestCase::redisPrefix().'other:queues:emails', [QueuePayloads::encode(queueStateJob(QUEUE_STATE_NOW - 3))]);

    $snapshot = app(QueueInspector::class)->inspect('other', 'emails', queueStateNow());

    expect($snapshot->connection)->toBe('other')
        ->and($snapshot->length)->toBe(1)
        ->and(queueStateInspect('emails')->length)->toBe(0);
});

it('fails a queue whose key holds another type', function (): void {
    queueStateRedis()->set('queues:broken', 'not a list');

    queueStateInspect('broken');
})->throws(RedisScriptFailed::class, 'WRONGTYPE');

it('rejects a reply of another shape', function (): void {
    $directory = sys_get_temp_dir().'/ho-queue-state-'.getmypid().'-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/queue_state.lua', 'return {1, 2, 3}');

    try {
        $inspector = new QueueInspector(app(RedisClients::class), new Lua($directory), app(Topology::class), app(Config::class));
        $inspector->inspect('redis', 'default', queueStateNow());
    } finally {
        unlink($directory.'/queue_state.lua');
        rmdir($directory);
    }
})->throws(UnexpectedValueException::class, 'queue_state returned array instead of 9 values.');
