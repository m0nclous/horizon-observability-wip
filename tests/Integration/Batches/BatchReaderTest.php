<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Batches\BatchKind;
use DF\HorizonObservability\Batches\BatchReader;
use DF\HorizonObservability\Batches\BatchSnapshot;
use DF\HorizonObservability\Batches\BatchStatus;
use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\Tests\Integration\Batches\Support\BatchTable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| BatchReader on SQLite: detection, the two bounded queries, the status matrix and the windows.
*/

const BATCH_READER_TEST_NOW = 1_760_000_000;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestamp(BATCH_READER_TEST_NOW));
});

/**
 * @return array<string, BatchSnapshot> id => snapshot
 */
function batchReaderTestRead(): array
{
    $snapshots = [];

    foreach (app(BatchReader::class)->read(CarbonImmutable::createFromTimestamp(BATCH_READER_TEST_NOW)) as $snapshot) {
        $snapshots[$snapshot->id] = $snapshot;
    }

    return $snapshots;
}

describe('detection', function (): void {
    it('is available with the database driver and an existing table', function (): void {
        BatchTable::create();

        expect(app(BatchReader::class)->available())->toBeTrue();
    });

    it('is available when the driver is absent or "database", like Laravel reads it', function (?string $driver): void {
        BatchTable::create();
        config()->set('queue.batching.driver', $driver);

        expect(app(BatchReader::class)->available())->toBeTrue();
    })->with([null, 'database', 'Database']);

    test('dynamodb driver skipped', function (): void {
        // DynamoDB batching made every scrape of the pilot a 500; here the collector is simply not run.
        BatchTable::create();
        config()->set('queue.batching.driver', 'dynamodb');
        DB::connection(BatchTable::CONNECTION)->enableQueryLog();

        expect(app(BatchReader::class)->available())->toBeFalse()
            ->and(DB::connection(BatchTable::CONNECTION)->getQueryLog())->toBe([]);
    });

    it('is not available without the table', function (): void {
        config()->set('queue.batching.database', BatchTable::CONNECTION);

        expect(app(BatchReader::class)->available())->toBeFalse();
    });

    it('is not available with another table name', function (): void {
        BatchTable::create('other_batches');
        config()->set('queue.batching.table', 'job_batches');

        expect(app(BatchReader::class)->available())->toBeFalse();
    });

    it('is not available for an unconfigured batching connection', function (): void {
        config()->set('queue.batching.database', 'missing');

        expect(app(BatchReader::class)->available())->toBeFalse();
    });

    it('is not available for a missing SQLite database file', function (): void {
        config()->set('database.connections.batches_file', ['driver' => 'sqlite', 'database' => sys_get_temp_dir().'/ho-missing-'.getmypid().'.sqlite', 'prefix' => '']);
        config()->set('queue.batching.database', 'batches_file');

        expect(app(BatchReader::class)->available())->toBeFalse();
    });

    it('uses the default connection when queue.batching.database is null', function (): void {
        BatchTable::create();
        config()->set('queue.batching.database');
        config()->set('database.default', BatchTable::CONNECTION);

        $reader = app(BatchReader::class);

        expect($reader->connectionName())->toBe(BatchTable::CONNECTION)
            ->and($reader->table())->toBe('job_batches')
            ->and($reader->driver())->toBe('database')
            ->and($reader->available())->toBeTrue();
    });

    it('throws when the database cannot be asked, and memoises nothing', function (): void {
        // A real failure, not "no batching": the exporter reports exporter_collector_success 0.
        config()->set('database.connections.unreachable', [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app', 'username' => 'app', 'password' => '',
            'options' => [PDO::ATTR_TIMEOUT => 1],
        ]);
        config()->set('queue.batching.database', 'unreachable');
        $reader = app(BatchReader::class);
        $failures = 0;

        foreach ([1, 2] as $attempt) {
            try {
                $reader->available();
            } catch (QueryException) {
                $failures++;
            }
        }

        // A memoised or cached answer would have made the second call return instead of asking again.
        expect($failures)->toBe(2);
    });

    it('memoises the table check for 10 minutes', function (): void {
        config()->set('queue.batching.database', BatchTable::CONNECTION);
        DB::connection(BatchTable::CONNECTION)->enableQueryLog();

        expect(app(BatchReader::class)->available())->toBeFalse();

        BatchTable::create();
        DB::connection(BatchTable::CONNECTION)->flushQueryLog();

        // Another instance in the same process: the memo answers, no query.
        expect(app(BatchReader::class)->available())->toBeFalse()
            ->and(DB::connection(BatchTable::CONNECTION)->getQueryLog())->toBe([]);

        Cache::store()->flush();
        $this->travel(BatchReader::DETECTION_TTL_SECONDS + 1)->seconds();

        expect(app(BatchReader::class)->available())->toBeTrue()
            ->and(DB::connection(BatchTable::CONNECTION)->getQueryLog())->toHaveCount(1);
    });

    it('shares the table check through the cache', function (): void {
        BatchTable::create();

        expect(app(BatchReader::class)->available())->toBeTrue();

        // A new database manager stands for another process: no memo, and a fresh in-memory database without the
        // table. The cached answer is used for 10 minutes.
        app()->forgetInstance('db');
        app()->forgetInstance(BatchReader::class);

        expect(app(BatchReader::class)->available())->toBeTrue()
            ->and(Schema::connection(BatchTable::CONNECTION)->hasTable('job_batches'))->toBeFalse();

        Cache::store()->flush();
        app()->forgetInstance('db');

        expect(app(BatchReader::class)->available())->toBeFalse();
    });

    it('expires a cached answer 10 minutes after the check, not 10 minutes after it was read', function (): void {
        config()->set('queue.batching.database', BatchTable::CONNECTION);

        // Another process checks: no table yet; the answer is shared through the cache.
        expect(app(BatchReader::class)->available())->toBeFalse();

        // This process (a new database manager) reads the shared answer 9 minutes later, then the table is created.
        $this->travel(BatchReader::DETECTION_TTL_SECONDS - 60)->seconds();
        app()->forgetInstance('db');

        expect(app(BatchReader::class)->available())->toBeFalse();

        BatchTable::create();
        $this->travel(61)->seconds();

        // 10 minutes and 1 second after the check: noticed, although this process read the answer only 61 s ago.
        expect(app(BatchReader::class)->available())->toBeTrue();
    });

    it('keys the check by where the connection points', function (): void {
        BatchTable::create();

        expect(app(BatchReader::class)->available())->toBeTrue();

        // Same connection name, another database: a new check, not the memoised answer.
        config()->set('database.connections.'.BatchTable::CONNECTION.'.database', sys_get_temp_dir().'/ho-missing-'.getmypid().'.sqlite');
        app()->forgetInstance('db');

        expect(app(BatchReader::class)->available())->toBeFalse();
    });

    it('works without a usable cache store', function (): void {
        BatchTable::create();
        config()->set('cache.default', 'broken');
        config()->set('cache.stores.broken', ['driver' => 'unknown-driver']);

        expect(app(BatchReader::class)->available())->toBeTrue();
    });
});

describe('reading', function (): void {
    it('classifies every status (status matrix)', function (): void {
        BatchTable::create();
        $now = BATCH_READER_TEST_NOW;
        $ids = [
            'running' => BatchTable::insert(['name' => 'running', 'total' => 10, 'pending' => 4, 'failed' => 0, 'created' => $now - 600]),
            'running with failures' => BatchTable::insert(['name' => 'retrying', 'total' => 10, 'pending' => 4, 'failed' => 1, 'created' => $now - 590]),
            'new without jobs yet' => BatchTable::insert(['name' => 'new', 'total' => 0, 'pending' => 0, 'failed' => 0, 'created' => $now - 5]),
            'finished' => BatchTable::insert(['name' => 'done', 'total' => 10, 'pending' => 0, 'failed' => 0, 'created' => $now - 900, 'finished' => $now - 60]),
            'finished with failures' => BatchTable::insert(['name' => 'done-failed', 'total' => 10, 'pending' => 2, 'failed' => 2, 'created' => $now - 880, 'finished' => $now - 50]),
            'allowFailures closed' => BatchTable::insert(['name' => 'allow', 'total' => 10, 'pending' => 3, 'failed' => 3, 'created' => $now - 870]),
            'cancelled' => BatchTable::insert(['name' => 'stopped', 'total' => 10, 'pending' => 6, 'failed' => 1, 'created' => $now - 860, 'cancelled' => $now - 40]),
            'cancelled then finished' => BatchTable::insert(['name' => 'stopped-done', 'total' => 10, 'pending' => 0, 'failed' => 0, 'created' => $now - 850, 'cancelled' => $now - 30, 'finished' => $now - 20]),
        ];

        $read = batchReaderTestRead();
        $status = static fn (string $case): array => [
            $read[$ids[$case]]->status,
            $read[$ids[$case]]->closedAt,
            $read[$ids[$case]]->progress,
        ];

        expect($status('running'))->toBe([BatchStatus::Running, null, 60])
            ->and($status('running with failures'))->toBe([BatchStatus::Running, null, 60])
            ->and($status('new without jobs yet'))->toBe([BatchStatus::Running, null, 0])
            ->and($status('finished'))->toBe([BatchStatus::Finished, $now - 60, 100])
            ->and($status('finished with failures'))->toBe([BatchStatus::Failures, $now - 50, 80])
            ->and($status('allowFailures closed'))->toBe([BatchStatus::Failures, $now - 870, 70])
            ->and($status('cancelled'))->toBe([BatchStatus::Cancelled, $now - 40, 40])
            ->and($status('cancelled then finished'))->toBe([BatchStatus::Cancelled, $now - 30, 100])
            ->and($read)->toHaveCount(8);
    });

    it('returns typed snapshots newest first with kinds, names and times in Unix seconds', function (): void {
        BatchTable::create();
        $now = BATCH_READER_TEST_NOW;
        $old = BatchTable::insert(['id' => 'b-old', 'name' => 'campaign_launch_15_2026-10-15', 'created' => $now - 300, 'total' => 4, 'pending' => 1]);
        $new = BatchTable::insert(['id' => 'b-new', 'name' => '', 'created' => $now - 100, 'total' => 2, 'pending' => 0, 'finished' => $now - 10]);

        $snapshots = app(BatchReader::class)->read(CarbonImmutable::createFromTimestamp($now));

        expect($snapshots)->toEqual([
            new BatchSnapshot($new, '', 'unnamed', BatchStatus::Finished, 2, 0, 0, 100, $now - 100, $now - 10),
            new BatchSnapshot($old, 'campaign_launch_15_2026-10-15', 'campaign_launch', BatchStatus::Running, 4, 1, 0, 75, $now - 300, null),
        ]);
    });

    it('resolves kinds with the hook', function (): void {
        BatchTable::create();
        HorizonObservability::resolveBatchKindUsing(static fn (?string $name, string $id): string => $name === null ? 'id:'.$id : 'kind:'.$name);
        BatchTable::insert(['id' => 'b1', 'name' => 'Import 42']);
        BatchTable::insert(['id' => 'b2', 'name' => '']);

        $read = batchReaderTestRead();

        expect($read['b1']->kind)->toBe('kind:Import 42')
            ->and($read['b2']->kind)->toBe('id:b2');
    });

    it('reads open batches of the last 7 days only', function (): void {
        BatchTable::create();
        $now = BATCH_READER_TEST_NOW;
        BatchTable::insert(['id' => 'lost', 'created' => $now - BatchReader::RUNNING_WINDOW_SECONDS - 1]);
        BatchTable::insert(['id' => 'edge', 'created' => $now - BatchReader::RUNNING_WINDOW_SECONDS]);
        BatchTable::insert(['id' => 'recent', 'created' => $now - 86400 * 6]);

        expect(array_keys(batchReaderTestRead()))->toBe(['recent', 'edge']);
    });

    it('reads closed batches of the last hour only', function (): void {
        BatchTable::create();
        $now = BATCH_READER_TEST_NOW;
        $hour = BatchReader::CLOSED_WINDOW_SECONDS;
        BatchTable::insert(['id' => 'finished-old', 'pending' => 0, 'created' => $now - 3 * $hour, 'finished' => $now - $hour - 1]);
        BatchTable::insert(['id' => 'finished-edge', 'pending' => 0, 'created' => $now - 3 * $hour, 'finished' => $now - $hour]);
        BatchTable::insert(['id' => 'cancelled-old', 'created' => $now - 3 * $hour, 'cancelled' => $now - 2 * $hour]);
        BatchTable::insert(['id' => 'cancelled-recent', 'created' => $now - 3 * $hour, 'cancelled' => $now - 60]);
        // Cancelled two hours ago, its last job finished 10 minutes ago: closed when it was cancelled.
        BatchTable::insert(['id' => 'cancelled-old-finished-recent', 'pending' => 0, 'created' => $now - 3 * $hour, 'cancelled' => $now - 2 * $hour, 'finished' => $now - 600]);
        // allowFailures batches without finished_at close at created_at.
        BatchTable::insert(['id' => 'allow-old', 'total' => 5, 'pending' => 2, 'failed' => 2, 'created' => $now - $hour - 1]);
        BatchTable::insert(['id' => 'allow-recent', 'total' => 5, 'pending' => 2, 'failed' => 2, 'created' => $now - $hour + 60]);

        $read = batchReaderTestRead();
        ksort($read);

        expect(array_keys($read))->toBe(['allow-recent', 'cancelled-recent', 'finished-edge']);
    });

    it('limits each query to the 5000 newest rows', function (): void {
        BatchTable::create();
        $now = BATCH_READER_TEST_NOW;
        $rows = [];

        for ($i = 0; $i < BatchReader::LIMIT + 3; $i++) {
            $rows[] = ['id' => sprintf('open-%05d', $i), 'created' => $now - 10 - $i];
            $rows[] = ['id' => sprintf('closed-%05d', $i), 'pending' => 0, 'created' => $now - 20 - $i, 'finished' => $now - 5];
        }

        BatchTable::insertMany($rows);

        $read = batchReaderTestRead();
        $open = array_filter($read, static fn (BatchSnapshot $batch): bool => $batch->status === BatchStatus::Running);

        expect($read)->toHaveCount(2 * BatchReader::LIMIT)
            ->and($open)->toHaveCount(BatchReader::LIMIT)
            ->and(isset($read['open-04999']))->toBeTrue()
            ->and(isset($read['open-05000']))->toBeFalse()
            ->and(isset($read['closed-04999']))->toBeTrue()
            ->and(isset($read['closed-05000']))->toBeFalse();
    });

    it('does not spend the limit on closed allowFailures batches it would discard', function (): void {
        BatchTable::create();
        $now = BATCH_READER_TEST_NOW;
        $rows = [];

        for ($i = 0; $i < BatchReader::LIMIT; $i++) {
            // Newer than the running batch below, but closed (pending == failed) more than an hour ago.
            $rows[] = ['id' => sprintf('allow-%05d', $i), 'total' => 3, 'pending' => 1, 'failed' => 1, 'created' => $now - 7200 + $i % 1000];
        }

        $rows[] = ['id' => 'running', 'created' => $now - 86400];
        BatchTable::insertMany($rows);

        expect(array_keys(batchReaderTestRead()))->toBe(['running']);
    });

    it('accepts numeric strings and nulls from the driver', function (): void {
        $snapshot = BatchReader::snapshot((object) [
            'id' => 'b', 'name' => null, 'total_jobs' => '8', 'pending_jobs' => '2', 'failed_jobs' => '0',
            'created_at' => '1700000000', 'finished_at' => null, 'cancelled_at' => null,
        ], new BatchKind);

        expect($snapshot)->toEqual(new BatchSnapshot('b', '', 'unnamed', BatchStatus::Running, 8, 2, 0, 75, 1700000000, null));
    });

    it('throws for a driver other than database (collectors.batches = true)', function (): void {
        BatchTable::create();
        config()->set('queue.batching.driver', 'dynamodb');

        app(BatchReader::class)->read(CarbonImmutable::createFromTimestamp(BATCH_READER_TEST_NOW));
    })->throws(RuntimeException::class, 'queue.batching.driver is [dynamodb]');

    it('throws for a missing table (collectors.batches = true)', function (): void {
        config()->set('queue.batching.database', BatchTable::CONNECTION);

        app(BatchReader::class)->read(CarbonImmutable::createFromTimestamp(BATCH_READER_TEST_NOW));
    })->throws(QueryException::class, 'job_batches');
});
