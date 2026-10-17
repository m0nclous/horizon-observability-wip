<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Integration\Batches\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The batch table of the batch tests: Laravel's `job_batches` migration (`queue:batches-table` stub) on the in-memory
 * `testing` SQLite connection, which `queue.batching.database` is pointed at.
 */
final class BatchTable
{
    public const CONNECTION = 'testing';

    /**
     * Point `queue.batching` at the test connection and create the table.
     */
    public static function create(string $table = 'job_batches'): void
    {
        config()->set('queue.batching.database', self::CONNECTION);
        config()->set('queue.batching.table', $table);

        Schema::connection(self::CONNECTION)->create($table, static function (Blueprint $blueprint): void {
            $blueprint->string('id')->primary();
            $blueprint->string('name');
            $blueprint->integer('total_jobs');
            $blueprint->integer('pending_jobs');
            $blueprint->integer('failed_jobs');
            $blueprint->longText('failed_job_ids');
            $blueprint->mediumText('options')->nullable();
            $blueprint->integer('cancelled_at')->nullable();
            $blueprint->integer('created_at');
            $blueprint->integer('finished_at')->nullable();
        });
    }

    /**
     * Insert one batch row; the id is generated unless given.
     *
     * @param  array{id?: string, name?: string, total?: int, pending?: int, failed?: int, created?: int, finished?: int|null, cancelled?: int|null}  $batch
     */
    public static function insert(array $batch, string $table = 'job_batches'): string
    {
        $id = $batch['id'] ?? (string) Str::uuid();

        DB::connection(self::CONNECTION)->table($table)->insert(self::row($id, $batch));

        return $id;
    }

    /**
     * Insert many rows in chunks.
     *
     * @param  list<array{id?: string, name?: string, total?: int, pending?: int, failed?: int, created?: int, finished?: int|null, cancelled?: int|null}>  $batches
     */
    public static function insertMany(array $batches, string $table = 'job_batches'): void
    {
        $rows = array_map(static fn (array $batch): array => self::row($batch['id'] ?? (string) Str::uuid(), $batch), $batches);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection(self::CONNECTION)->table($table)->insert($chunk);
        }
    }

    /**
     * @param  array{id?: string, name?: string, total?: int, pending?: int, failed?: int, created?: int, finished?: int|null, cancelled?: int|null}  $batch
     * @return array<string, int|string|null>
     */
    private static function row(string $id, array $batch): array
    {
        return [
            'id' => $id,
            'name' => $batch['name'] ?? '',
            'total_jobs' => $batch['total'] ?? 10,
            'pending_jobs' => $batch['pending'] ?? 10,
            'failed_jobs' => $batch['failed'] ?? 0,
            'failed_job_ids' => '[]',
            'options' => null,
            'cancelled_at' => $batch['cancelled'] ?? null,
            'created_at' => $batch['created'] ?? now()->getTimestamp(),
            'finished_at' => $batch['finished'] ?? null,
        ];
    }
}
