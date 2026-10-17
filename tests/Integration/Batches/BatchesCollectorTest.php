<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Collectors\BatchesCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\Batches\Support\BatchTable;
use DF\HorizonObservability\Tests\Integration\Batches\Support\Families;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| BatchesCollector (Arch Q4, Q6.7): the queue_batch* families from the batch table and the batch links, and the
| partial failure of each source (C1).
*/

pest()->group('redis');

const BATCHES_COLLECTOR_TEST_NOW = 1_760_000_000;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestamp(BATCHES_COLLECTOR_TEST_NOW));
});

function batchesCollectorTestContext(float $budget = 10.0): ScrapeContext
{
    return new ScrapeContext(CarbonImmutable::createFromTimestamp(BATCHES_COLLECTOR_TEST_NOW), $budget);
}

/**
 * @return array<string, array<string, int|float>>
 */
function batchesCollectorTestCollect(?ScrapeContext $context = null): array
{
    $context ??= batchesCollectorTestContext();
    $families = Families::of(app(BatchesCollector::class)->collect($context));

    expect($context->takeFailures())->toBe([]);

    return $families;
}

function batchesCollectorTestLink(string $kind, string $connection, string $queue, string $jobClass, int $seenAt = BATCHES_COLLECTOR_TEST_NOW): void
{
    app(RedisClients::class)->storage()->zadd((new Keys)->batchLinks(), $seenAt, Keys::field($kind, $connection, $queue, $jobClass));
}

it('is the batches collector', function (): void {
    expect(app(BatchesCollector::class)->name())->toBe('batches')
        ->and(Metric::ofCollector('batches'))->toHaveCount(9);
});

it('exports every batches family from the table and the links', function (): void {
    BatchTable::create();
    $now = BATCHES_COLLECTOR_TEST_NOW;
    BatchTable::insert(['id' => 'i-1', 'name' => 'Import 2026-10-17', 'total' => 10, 'pending' => 4, 'failed' => 1, 'created' => $now - 600]);
    BatchTable::insert(['id' => 'i-2', 'name' => 'Import 2026-10-16', 'total' => 5, 'pending' => 5, 'failed' => 0, 'created' => $now - 300]);
    BatchTable::insert(['id' => 'i-3', 'name' => 'Import 2026-10-15', 'total' => 8, 'pending' => 0, 'failed' => 0, 'created' => $now - 1200, 'finished' => $now - 60]);
    BatchTable::insert(['id' => 'e-1', 'name' => 'export-ab3f9e', 'total' => 4, 'pending' => 1, 'failed' => 1, 'created' => $now - 900, 'cancelled' => $now - 30]);
    BatchTable::insert(['id' => 'u-1', 'name' => '', 'total' => 3, 'pending' => 3, 'failed' => 0, 'created' => $now - 100]);
    batchesCollectorTestLink('Import', 'redis', 'default', 'App\\Jobs\\ImportRow');
    batchesCollectorTestLink('Import', 'redis', 'slow', 'App\\Jobs\\ImportRow');
    batchesCollectorTestLink('Import', 'redis-eu', 'default', 'App\\Jobs\\ImportFile');
    batchesCollectorTestLink('mailing', 'redis', 'mail', 'App\\Mail\\Digest', $now - 86400);

    expect(batchesCollectorTestCollect())->toBe([
        'queue_batches_running' => [
            'batch="Import"' => 2,
            'batch="export"' => 0,
            'batch="unnamed"' => 1,
        ],
        'queue_batch_jobs' => [
            'batch="Import",state="failed"' => 1,
            'batch="Import",state="pending"' => 9,
            'batch="Import",state="total"' => 15,
            'batch="export",state="failed"' => 0,
            'batch="export",state="pending"' => 0,
            'batch="export",state="total"' => 0,
            'batch="unnamed",state="failed"' => 0,
            'batch="unnamed",state="pending"' => 3,
            'batch="unnamed",state="total"' => 3,
        ],
        'queue_batch_oldest_age_seconds' => [
            'batch="Import"' => 600,
            'batch="unnamed"' => 100,
        ],
        'queue_batch_job_counts' => [
            'batch="Import",name="Import 2026-10-15",state="failed"' => 0,
            'batch="Import",name="Import 2026-10-15",state="pending"' => 0,
            'batch="Import",name="Import 2026-10-15",state="total"' => 8,
            'batch="Import",name="Import 2026-10-16",state="failed"' => 0,
            'batch="Import",name="Import 2026-10-16",state="pending"' => 5,
            'batch="Import",name="Import 2026-10-16",state="total"' => 5,
            'batch="Import",name="Import 2026-10-17",state="failed"' => 1,
            'batch="Import",name="Import 2026-10-17",state="pending"' => 4,
            'batch="Import",name="Import 2026-10-17",state="total"' => 10,
            'batch="export",name="export-ab3f9e",state="failed"' => 1,
            'batch="export",name="export-ab3f9e",state="pending"' => 1,
            'batch="export",name="export-ab3f9e",state="total"' => 4,
            'batch="unnamed",name="u-1",state="failed"' => 0,
            'batch="unnamed",name="u-1",state="pending"' => 3,
            'batch="unnamed",name="u-1",state="total"' => 3,
        ],
        'queue_batch_created_timestamp_seconds' => [
            'batch="Import",name="Import 2026-10-15"' => $now - 1200,
            'batch="Import",name="Import 2026-10-16"' => $now - 300,
            'batch="Import",name="Import 2026-10-17"' => $now - 600,
            'batch="export",name="export-ab3f9e"' => $now - 900,
            'batch="unnamed",name="u-1"' => $now - 100,
        ],
        'queue_batch_closed_timestamp_seconds' => [
            'batch="Import",name="Import 2026-10-15"' => $now - 60,
            'batch="export",name="export-ab3f9e"' => $now - 30,
        ],
        'queue_batch_job_classes' => [
            'batch="Import",queue="default",job_class="App\\Jobs\\ImportFile"' => 1,
            'batch="Import",queue="default",job_class="App\\Jobs\\ImportRow"' => 1,
            'batch="Import",queue="slow",job_class="App\\Jobs\\ImportRow"' => 1,
            'batch="mailing",queue="mail",job_class="App\\Mail\\Digest"' => 1,
        ],
        'queue_batch_info' => [
            'batch="Import",queue="default, slow",connection="redis, redis-eu"' => 1,
            'batch="mailing",queue="mail",connection="redis"' => 1,
        ],
        'queue_batch_progress' => [
            'batch="Import",name="Import 2026-10-15",status="Finished",id="i-3"' => 100,
            'batch="Import",name="Import 2026-10-16",status="Running",id="i-2"' => 0,
            'batch="Import",name="Import 2026-10-17",status="Running",id="i-1"' => 60,
            'batch="export",name="export-ab3f9e",status="Cancelled",id="e-1"' => 75,
            'batch="unnamed",name="u-1",status="Running",id="u-1"' => 0,
        ],
    ]);
});

it('exports nothing without batches and links', function (): void {
    BatchTable::create();

    expect(batchesCollectorTestCollect())->toBe([]);
});

it('exports the newest batch of a kind and name only, but counts all of them per kind', function (): void {
    BatchTable::create();
    $now = BATCHES_COLLECTOR_TEST_NOW;
    BatchTable::insert(['id' => 'old', 'name' => 'Nightly import', 'total' => 10, 'pending' => 10, 'created' => $now - 7200]);
    BatchTable::insert(['id' => 'new', 'name' => 'Nightly import', 'total' => 4, 'pending' => 2, 'created' => $now - 60]);

    $families = batchesCollectorTestCollect();

    expect($families['queue_batches_running'])->toBe(['batch="Nightly import"' => 2])
        ->and($families['queue_batch_jobs']['batch="Nightly import",state="total"'])->toBe(14)
        ->and($families['queue_batch_oldest_age_seconds'])->toBe(['batch="Nightly import"' => 7200])
        ->and($families['queue_batch_progress'])->toBe(['batch="Nightly import",name="Nightly import",status="Running",id="new"' => 50])
        ->and($families['queue_batch_created_timestamp_seconds'])->toBe(['batch="Nightly import",name="Nightly import"' => $now - 60]);
});

it('labels a batch whose name sanitises to nothing by its id', function (): void {
    BatchTable::create();
    BatchTable::insert(['id' => 'b-1', 'name' => "\x01\x02"]);

    expect(array_keys(batchesCollectorTestCollect()['queue_batch_progress']))->toBe(['batch="unnamed",name="b-1",status="Running",id="b-1"']);
});

it('prunes the links once per scrape and reads them with one command', function (): void {
    BatchTable::create();
    batchesCollectorTestLink('import', 'redis', 'default', 'A');
    batchesCollectorTestLink('stale', 'redis', 'default', 'B', BATCHES_COLLECTOR_TEST_NOW - BatchLinks::TTL_SECONDS - 1);
    $commands = RedisCommands::start(app());

    $families = batchesCollectorTestCollect();

    expect($commands->names(RedisClients::STORAGE))->toBe(['ZRANGE', 'ZREMRANGEBYSCORE'])
        ->and(app(RedisClients::class)->storage()->zcard((new Keys)->batchLinks()))->toBe(1)
        ->and(array_keys($families['queue_batch_info']))->toBe(['batch="import",queue="default",connection="redis"']);
});

it('runs two SQL queries per scrape', function (): void {
    BatchTable::create();
    DB::connection(BatchTable::CONNECTION)->enableQueryLog();

    batchesCollectorTestCollect();

    expect(DB::connection(BatchTable::CONNECTION)->getQueryLog())->toHaveCount(2);
});

it('keeps the links when the batch table fails (C1, collectors.batches = true)', function (): void {
    // No table: the read fails, which is a reported failure, not a 500 and not a lost scrape.
    config()->set('queue.batching.database', BatchTable::CONNECTION);
    batchesCollectorTestLink('import', 'redis', 'default', 'A');
    $context = batchesCollectorTestContext();

    $families = Families::of(app(BatchesCollector::class)->collect($context));
    $failures = $context->takeFailures();

    expect(array_keys($families))->toBe(['queue_batch_job_classes', 'queue_batch_info'])
        ->and($failures)->toHaveCount(1)
        ->and($failures[0]['source'])->toBe('database')
        ->and($failures[0]['exception'])->toBeInstanceOf(QueryException::class);
});

it('keeps the table data when the links cannot be read', function (): void {
    BatchTable::create();
    BatchTable::insert(['id' => 'b', 'name' => 'import']);
    config()->set('database.redis.horizon.port', 1);
    config()->set('database.redis.horizon.max_retries', 0);

    foreach ([Config::class, RedisClients::class, BatchLinks::class] as $abstract) {
        $this->app->forgetInstance($abstract);
    }

    $context = batchesCollectorTestContext();
    $families = Families::of(app(BatchesCollector::class)->collect($context));
    $failures = $context->takeFailures();

    expect($families['queue_batches_running'])->toBe(['batch="import"' => 1])
        ->and(isset($families['queue_batch_info']))->toBeFalse()
        ->and(array_column($failures, 'source'))->toBe(['links']);
});

it('reports an error reply of the links on both clients and keeps the table data', function (): void {
    // phpredis answers WRONGTYPE with false; it must not pass for an empty link set with success 1.
    BatchTable::create();
    BatchTable::insert(['id' => 'b', 'name' => 'import']);
    app(RedisClients::class)->storage()->set((new Keys)->batchLinks(), 'not a zset');
    $context = batchesCollectorTestContext();

    $families = Families::of(app(BatchesCollector::class)->collect($context));
    $failures = $context->takeFailures();

    expect($families['queue_batches_running'])->toBe(['batch="import"' => 1])
        ->and(array_column($failures, 'source'))->toBe(['links'])
        ->and($failures[0]['exception']->getMessage())->toContain('WRONGTYPE');
});

it('skips the links when the scrape budget has run out', function (): void {
    BatchTable::create();
    BatchTable::insert(['id' => 'b', 'name' => 'import']);
    batchesCollectorTestLink('import', 'redis', 'default', 'A');
    $context = batchesCollectorTestContext(budget: 0.0);
    $commands = RedisCommands::start(app());

    $families = Families::of(app(BatchesCollector::class)->collect($context));
    $failures = $context->takeFailures();

    expect(array_keys($families))->toContain('queue_batches_running')
        ->and(isset($families['queue_batch_info']))->toBeFalse()
        ->and($commands->count())->toBe(0)
        ->and(array_column($failures, 'source'))->toBe(['links']);
});
