<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Batches\BatchReader;
use DF\HorizonObservability\Tests\Integration\Batches\Support\BatchTable;
use Illuminate\Support\Facades\DB;

/*
| The pilot loaded every never-finished batch on every scrape, with one unbounded OR query. A scrape now runs exactly
| two queries, each bounded by a time window and LIMIT 5000, newest first (asserted on the query log).
*/

it('reads batches with two bounded queries', function (): void {
    BatchTable::create();
    $now = 1_760_000_000;
    BatchTable::insert(['created' => $now - 100]);
    $connection = DB::connection(BatchTable::CONNECTION);
    $connection->enableQueryLog();

    app(BatchReader::class)->read(CarbonImmutable::createFromTimestamp($now));

    $queries = $connection->getQueryLog();
    $columns = 'select "id", "name", "total_jobs", "pending_jobs", "failed_jobs", "created_at", "finished_at", "cancelled_at" from "job_batches"';

    expect($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toBe($columns.' where "finished_at" is null and "cancelled_at" is null and "created_at" >= ?'
            .' and not ("total_jobs" > ? and "pending_jobs" = "failed_jobs" and "created_at" < ?) order by "created_at" desc limit 5000')
        ->and($queries[0]['bindings'])->toBe([$now - 604800, 0, $now - 3600])
        ->and($queries[1]['query'])->toBe($columns.' where ("finished_at" >= ? or "cancelled_at" >= ?) order by "created_at" desc limit 5000')
        ->and($queries[1]['bindings'])->toBe([$now - 3600, $now - 3600]);
});

it('costs one table check per 10 minutes for detection', function (): void {
    BatchTable::create();
    $connection = DB::connection(BatchTable::CONNECTION);
    $connection->enableQueryLog();

    foreach (range(1, 5) as $scrape) {
        app(BatchReader::class)->available();
    }

    expect($connection->getQueryLog())->toHaveCount(1)
        ->and($connection->getQueryLog()[0]['query'])->toContain('sqlite_master');
});
