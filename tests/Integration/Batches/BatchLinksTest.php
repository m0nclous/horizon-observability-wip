<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Batches\BatchLink;
use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\Redis\CircuitBreaker;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\Batches\Support\BatchedJob;
use DF\HorizonObservability\Tests\Integration\Batches\Support\BatchTable;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/*
| BatchLinks (Arch Q6.7): the JobQueued listener (memo, breaker, redis connections only) and the reads (7-day window,
| prune), on phpredis and predis.
*/

pest()->group('redis');

const BATCH_LINKS_TEST_NOW = 1_760_000_000;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestamp(BATCH_LINKS_TEST_NOW));
    BatchTable::create();
});

/**
 * Members of the batch_links zset => score, read raw.
 *
 * @return array<string, int>
 */
function batchLinksTestStored(): array
{
    $key = TestCase::redisPrefix().'horizon:'.(new Keys)->batchLinks();
    $stored = [];

    /** @var array<string, string> $reply */
    $reply = RedisKeys::client()->zrange($key, 0, -1, ['withscores' => true]);

    foreach ($reply as $member => $score) {
        $stored[(string) $member] = (int) $score;
    }

    return $stored;
}

function batchLinksTestSeed(string $member, int $score): void
{
    app(RedisClients::class)->storage()->zadd((new Keys)->batchLinks(), $score, $member);
}

/**
 * A JobQueued event of a batched job, as Laravel raises it.
 */
function batchLinksTestEvent(string $batchId, string $connection = 'redis', ?string $queue = 'default', string $displayName = BatchedJob::class): JobQueued
{
    $job = (new BatchedJob)->withBatchId($batchId);

    return new JobQueued($connection, $queue, 'job-id', $job, (string) json_encode(['displayName' => $displayName, 'uuid' => 'job-id']), null);
}

describe('writing', function (): void {
    it('records the links of a dispatched batch on the storage connection', function (): void {
        Bus::batch([new BatchedJob, new BatchedJob])->name('Import 2026-10-17')->onConnection('redis')->dispatch();
        Bus::batch([new BatchedJob])->name('Mailing 15')->onConnection('redis')->onQueue('mail')->dispatch();

        expect(batchLinksTestStored())->toBe([
            Keys::field('Import', 'redis', 'default', BatchedJob::class) => BATCH_LINKS_TEST_NOW,
            Keys::field('Mailing', 'redis', 'mail', BatchedJob::class) => BATCH_LINKS_TEST_NOW,
        ])->and(RedisKeys::all(TestCase::redisPrefix().'horizon:{horizon-observability}'))->toBe([
            TestCase::redisPrefix().'horizon:{horizon-observability}:v1:batch_links',
        ]);
    });

    it('writes a link at most once per hour per process', function (): void {
        $commands = RedisCommands::start(app());

        Bus::batch([new BatchedJob, new BatchedJob, new BatchedJob])->name('Import 1')->onConnection('redis')->dispatch();
        Bus::batch([new BatchedJob])->name('Import 2')->onConnection('redis')->dispatch();
        $this->travel(BatchLinks::REFRESH_SECONDS - 1)->seconds();
        Bus::batch([new BatchedJob])->name('Import 3')->onConnection('redis')->dispatch();

        // The first write of the hour also prunes.
        expect($commands->names(RedisClients::STORAGE))->toBe(['ZADD', 'ZREMRANGEBYSCORE'])
            ->and(batchLinksTestStored())->toBe([Keys::field('Import', 'redis', 'default', BatchedJob::class) => BATCH_LINKS_TEST_NOW]);

        $this->travel(1)->seconds();
        Bus::batch([new BatchedJob])->name('Import 4')->onConnection('redis')->dispatch();

        expect($commands->names(RedisClients::STORAGE))->toBe(['ZADD', 'ZREMRANGEBYSCORE', 'ZADD', 'ZREMRANGEBYSCORE'])
            ->and(batchLinksTestStored())->toBe([Keys::field('Import', 'redis', 'default', BatchedJob::class) => BATCH_LINKS_TEST_NOW + BatchLinks::REFRESH_SECONDS]);
    });

    it('prunes links older than 7 days with the first write of an hour', function (): void {
        // Keeps the set bounded when the batches collector never runs (auto mode with DynamoDB batching).
        batchLinksTestSeed(Keys::field('stale', 'redis', 'default', 'X'), BATCH_LINKS_TEST_NOW - BatchLinks::TTL_SECONDS - 1);
        $batch = Bus::batch([])->name('Import')->onConnection('redis')->dispatch();
        $commands = RedisCommands::start(app());
        $links = app(BatchLinks::class);

        $links->onQueued(batchLinksTestEvent($batch->id, queue: 'a'));
        $links->onQueued(batchLinksTestEvent($batch->id, queue: 'b'));

        expect($commands->names(RedisClients::STORAGE))->toBe(['ZADD', 'ZREMRANGEBYSCORE', 'ZADD'])
            ->and(array_keys(batchLinksTestStored()))->toBe([
                Keys::field('Import', 'redis', 'a', BatchedJob::class),
                Keys::field('Import', 'redis', 'b', BatchedJob::class),
            ]);
    });

    it('looks a batch up once per batch id', function (): void {
        $connection = DB::connection(BatchTable::CONNECTION);
        $batch = Bus::batch([])->name('Import')->onConnection('redis')->dispatch();
        $connection->enableQueryLog();

        foreach (range(1, 5) as $job) {
            app(BatchLinks::class)->onQueued(batchLinksTestEvent($batch->id, queue: 'queue-'.$job));
        }

        $lookups = array_filter($connection->getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'select'));

        expect($lookups)->toHaveCount(1)
            ->and(batchLinksTestStored())->toHaveCount(5);
    });

    it('starts the memos over when they are full', function (): void {
        $links = app(BatchLinks::class);
        $connection = DB::connection(BatchTable::CONNECTION);
        $connection->enableQueryLog();

        foreach (range(0, BatchLinks::MEMO_LIMIT) as $i) {
            $links->onQueued(batchLinksTestEvent('missing-'.$i));
        }

        $connection->flushQueryLog();
        $links->onQueued(batchLinksTestEvent('missing-1'));

        // The batch memo was cleared at the 1001st id, so the first id is looked up again; its link is still fresh.
        expect($connection->getQueryLog())->toHaveCount(1)
            ->and(batchLinksTestStored())->toBe([Keys::field('unnamed', 'redis', 'default', BatchedJob::class) => BATCH_LINKS_TEST_NOW]);
    });

    it('uses the batch kind hook and names unknown or unnamed batches unnamed', function (): void {
        HorizonObservability::resolveBatchKindUsing(static fn (?string $name, string $id): string => $name === null ? 'anonymous' : 'hooked-'.$name);

        Bus::batch([new BatchedJob])->name('Import 7')->onConnection('redis')->dispatch();
        Bus::batch([new BatchedJob])->onConnection('redis')->dispatch();
        HorizonObservability::resolveBatchKindUsing(null);
        app(BatchLinks::class)->onQueued(batchLinksTestEvent('no-such-batch', queue: 'other'));

        expect(array_keys(batchLinksTestStored()))->toBe([
            Keys::field('anonymous', 'redis', 'default', BatchedJob::class),
            Keys::field('hooked-Import 7', 'redis', 'default', BatchedJob::class),
            Keys::field('unnamed', 'redis', 'other', BatchedJob::class),
        ]);
    });

    it('labels the job class like every other job_class label', function (): void {
        $batch = Bus::batch([])->name('Import')->onConnection('redis')->dispatch();
        $links = app(BatchLinks::class);

        $links->onQueued(batchLinksTestEvent($batch->id, displayName: 'Closure (routes/web.php:42)'));
        $links->onQueued(batchLinksTestEvent($batch->id, displayName: ''));
        $links->onQueued(batchLinksTestEvent($batch->id, queue: "bad\x1fqueue", displayName: "App\\Jobs\\With\x1fSeparator"));

        expect(array_keys(batchLinksTestStored()))->toBe([
            Keys::field('Import', 'redis', 'badqueue', 'App\\Jobs\\WithSeparator'),
            Keys::field('Import', 'redis', 'default', '(unknown)'),
            Keys::field('Import', 'redis', 'default', 'Closure (routes/web.php)'),
        ]);
    });

    it("takes the connection's default queue when the event has none", function (): void {
        config()->set('queue.connections.redis.queue', 'fallback');
        $batch = Bus::batch([])->name('Import')->onConnection('redis')->dispatch();

        app(BatchLinks::class)->onQueued(batchLinksTestEvent($batch->id, queue: null));

        expect(array_keys(batchLinksTestStored()))->toBe([Keys::field('Import', 'redis', 'fallback', BatchedJob::class)]);
    });

    it('ignores jobs that are not batched or not on a redis connection', function (): void {
        $batch = Bus::batch([])->name('Import')->onConnection('redis')->dispatch();
        $links = app(BatchLinks::class);
        $commands = RedisCommands::start(app());

        $links->onQueued(new JobQueued('redis', 'default', 'id', new BatchedJob, '{"displayName":"x"}', null));
        $links->onQueued(new JobQueued('redis', 'default', 'id', 'App\\Jobs\\ByName', '{"displayName":"x"}', null));
        $links->onQueued(batchLinksTestEvent($batch->id, connection: 'database'));
        $links->onQueued(batchLinksTestEvent($batch->id, connection: 'sync'));
        $links->onQueued(batchLinksTestEvent($batch->id, connection: 'not-configured'));

        expect($commands->count())->toBe(0)
            ->and(batchLinksTestStored())->toBe([]);
    });

    it('never throws into the dispatching code', function (): void {
        // The batch lookup fails: the table is gone.
        DB::connection(BatchTable::CONNECTION)->getSchemaBuilder()->drop('job_batches');
        $log = new LogRecorder;
        $this->app->instance('log', $log);

        app(BatchLinks::class)->onQueued(batchLinksTestEvent('some-batch'));

        expect(batchLinksTestStored())->toBe([])
            ->and($log->messages('warning'))->toHaveCount(1)
            ->and($log->messages('warning')[0])->toStartWith('horizon-observability: batch_links failed: QueryException: ');
    });

    it('skips writes while the breaker is open without counting them as dropped attempts', function (): void {
        // C11: an unreachable storage costs one timeout per cooldown; the dispatch itself is unaffected.
        config()->set('database.redis.horizon.port', 1);
        config()->set('database.redis.horizon.max_retries', 0);
        config()->set('horizon-observability.redis.worker_timeout', 0.2);

        foreach ([Config::class, RedisClients::class, CircuitBreaker::class, BatchLinks::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $log = new LogRecorder;
        $this->app->instance('log', $log);
        $batch = Bus::batch([])->name('Import')->onConnection('redis')->dispatch();
        $links = app(BatchLinks::class);

        $started = hrtime(true);
        $links->onQueued(batchLinksTestEvent($batch->id, queue: 'a'));
        $links->onQueued(batchLinksTestEvent($batch->id, queue: 'b'));
        $links->onQueued(batchLinksTestEvent($batch->id, queue: 'c'));
        $elapsed = (hrtime(true) - $started) / 1e9;

        $breaker = app(CircuitBreaker::class);

        expect($breaker->isOpen())->toBeTrue()
            ->and($breaker->takeDropped())->toBe(1)
            ->and($elapsed)->toBeLessThan(1.0)
            ->and($log->messages('warning'))->toHaveCount(1)
            ->and($log->messages('warning')[0])->toStartWith('horizon-observability: breaker:batch_links failed: ');
    });
});

describe('reading', function (): void {
    it('reads the links of the last 7 days, sorted by link', function (): void {
        $now = BATCH_LINKS_TEST_NOW;
        batchLinksTestSeed(Keys::field('import', 'redis', 'default', 'App\\Jobs\\B'), $now - 60);
        batchLinksTestSeed(Keys::field('import', 'redis', 'default', 'App\\Jobs\\A'), $now - BatchLinks::TTL_SECONDS);
        batchLinksTestSeed(Keys::field('export', 'redis', 'slow', 'App\\Jobs\\C'), $now - 10);
        batchLinksTestSeed(Keys::field('stale', 'redis', 'default', 'App\\Jobs\\D'), $now - BatchLinks::TTL_SECONDS - 1);
        batchLinksTestSeed('not-a-link', $now);
        batchLinksTestSeed(Keys::field('import', '', 'default', 'App\\Jobs\\E'), $now);
        // A foreign member with a control character: read as the sanitised link, the later time wins.
        batchLinksTestSeed(Keys::field("exp\x01ort", 'redis', 'slow', 'App\\Jobs\\C'), $now - 5);

        $links = app(BatchLinks::class);

        expect($links->all())->toEqual([
            new BatchLink('export', 'redis', 'slow', 'App\\Jobs\\C'),
            new BatchLink('import', 'redis', 'default', 'App\\Jobs\\A'),
            new BatchLink('import', 'redis', 'default', 'App\\Jobs\\B'),
        ])->and(array_column($links->recent(CarbonImmutable::createFromTimestamp($now)), 'seenAt'))->toBe([$now - 5, $now - BatchLinks::TTL_SECONDS, $now - 60]);
    });

    it('gives the queues and job classes of batch kinds', function (): void {
        $now = BATCH_LINKS_TEST_NOW;
        batchLinksTestSeed(Keys::field('import', 'redis', 'default', 'App\\Jobs\\B'), $now);
        batchLinksTestSeed(Keys::field('import', 'redis', 'slow', 'App\\Jobs\\A'), $now);
        batchLinksTestSeed(Keys::field('import', 'other', 'slow', 'App\\Jobs\\A'), $now);
        batchLinksTestSeed(Keys::field('export', 'redis', 'mail', 'App\\Jobs\\C'), $now);
        batchLinksTestSeed(Keys::field('stale', 'redis', 'sms', 'App\\Jobs\\D'), $now - BatchLinks::TTL_SECONDS - 1);
        // A hook may return digits only.
        batchLinksTestSeed(Keys::field('42', 'redis', 'numbers', 'App\\Jobs\\N'), $now);

        $links = app(BatchLinks::class);

        expect($links->forKinds(['import']))->toBe(['queues' => ['default', 'slow'], 'jobs' => ['App\\Jobs\\A', 'App\\Jobs\\B']])
            ->and($links->forKinds(['42']))->toBe(['queues' => ['numbers'], 'jobs' => ['App\\Jobs\\N']])
            ->and($links->forKinds(['import', 'export']))->toBe(['queues' => ['default', 'mail', 'slow'], 'jobs' => ['App\\Jobs\\A', 'App\\Jobs\\B', 'App\\Jobs\\C']])
            ->and($links->forKinds(['stale']))->toBe(['queues' => [], 'jobs' => []])
            ->and($links->forKinds(['missing']))->toBe(['queues' => [], 'jobs' => []]);
    });

    it('reads at most the 10 000 most recently seen links', function (): void {
        // A kind hook that yields a kind per batch must not make every read grow with a week of batches.
        $now = BATCH_LINKS_TEST_NOW;
        $members = [];

        foreach (range(0, BatchLinks::MAX_LINKS) as $i) {
            $members[Keys::field('kind-'.$i, 'redis', 'default', 'X')] = $now - BatchLinks::MAX_LINKS + $i;
        }

        foreach (array_chunk($members, 1000, true) as $chunk) {
            RedisKeys::client()->zadd(TestCase::redisPrefix().'horizon:'.(new Keys)->batchLinks(), $chunk);
        }

        $commands = RedisCommands::start(app());
        $kinds = array_map(
            static fn (array $entry): string => $entry['link']->kind,
            app(BatchLinks::class)->recent(CarbonImmutable::createFromTimestamp($now)),
        );

        expect($kinds)->toHaveCount(BatchLinks::MAX_LINKS)
            ->and($kinds)->not->toContain('kind-0')
            ->and($kinds)->toContain('kind-1', 'kind-'.BatchLinks::MAX_LINKS)
            ->and($commands->names(RedisClients::STORAGE))->toBe(['ZRANGE']);
    });

    it('answers an empty kind list without a Redis command', function (): void {
        $commands = RedisCommands::start(app());

        expect(app(BatchLinks::class)->forKinds([]))->toBe(['queues' => [], 'jobs' => []])
            ->and($commands->count())->toBe(0);
    });

    it('reads with one command', function (): void {
        batchLinksTestSeed(Keys::field('import', 'redis', 'default', 'App\\Jobs\\A'), BATCH_LINKS_TEST_NOW);
        $commands = RedisCommands::start(app());

        app(BatchLinks::class)->forKinds(['import']);

        expect($commands->names(RedisClients::STORAGE))->toBe(['ZRANGE']);
    });

    it('prunes links older than 7 days', function (): void {
        $now = BATCH_LINKS_TEST_NOW;
        batchLinksTestSeed(Keys::field('a', 'redis', 'default', 'X'), $now - BatchLinks::TTL_SECONDS - 100);
        batchLinksTestSeed(Keys::field('b', 'redis', 'default', 'X'), $now - BatchLinks::TTL_SECONDS - 1);
        batchLinksTestSeed(Keys::field('c', 'redis', 'default', 'X'), $now - BatchLinks::TTL_SECONDS);
        batchLinksTestSeed(Keys::field('d', 'redis', 'default', 'X'), $now);

        expect(app(BatchLinks::class)->prune(CarbonImmutable::createFromTimestamp($now)))->toBe(2)
            ->and(array_keys(batchLinksTestStored()))->toBe([Keys::field('c', 'redis', 'default', 'X'), Keys::field('d', 'redis', 'default', 'X')])
            ->and(app(BatchLinks::class)->prune(CarbonImmutable::createFromTimestamp($now)))->toBe(0);
    });

    it('fails visibly when the storage cannot be read', function (): void {
        config()->set('database.redis.horizon.port', 1);
        config()->set('database.redis.horizon.max_retries', 0);

        foreach ([Config::class, RedisClients::class, BatchLinks::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $thrown = null;

        try {
            app(BatchLinks::class)->forKinds(['import']);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(Throwable::class);
    });
});
