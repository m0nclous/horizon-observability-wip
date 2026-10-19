<?php

declare(strict_types=1);

use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Console\DemoCommand;
use DF\HorizonObservability\Demo\DemoJob;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\Support\RunsWorker;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/*
| PUBLIC horizon-observability:demo: a short burst of sample jobs on the first Horizon queue of the
| default Redis connection (completed, failed, retried, released, delayed, one batch); refuses in production and is
| safe to run twice. A real worker gives the outcomes the summary promises.
*/

pest()->group('redis');
uses(RunsWorker::class);

function demoBatchTable(): void
{
    Schema::connection('testing')->create('job_batches', static function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
}

/**
 * @return array{ready: int, delayed: int}
 */
function demoQueue(string $queue = 'default'): array
{
    $raw = RedisKeys::client();

    return [
        'ready' => (int) $raw->llen(TestCase::redisPrefix().'queues:'.$queue),
        'delayed' => (int) $raw->zcard(TestCase::redisPrefix().'queues:'.$queue.':delayed'),
    ];
}

/**
 * Recorded attempts per status (all queues and classes).
 *
 * @return array<string, int>
 */
function demoAttempts(): array
{
    $totals = [];

    foreach (app(AttemptStore::class)->counters()['attempts'] as $field => $count) {
        $status = Keys::splitField($field)[3] ?? '?';
        $totals[$status] = ($totals[$status] ?? 0) + (int) $count;
    }

    ksort($totals);

    return $totals;
}

it('plans 10 % failures, 10 % retries, 5 % releases and 10 % delays, spread over the burst', function (): void {
    $plan = DemoCommand::plan(200);
    $counts = array_count_values(array_map(static fn (array $step): string => $step[1] > 0 ? 'delayed' : $step[0], $plan));
    ksort($counts);

    expect($plan)->toHaveCount(200)
        ->and($counts)->toBe(['completed' => 130, 'delayed' => 20, 'failed' => 20, 'released' => 10, 'retried' => 20])
        ->and(array_slice(array_column($plan, 0), 0, 10))->not->toBe(array_fill(0, 10, 'failed'))
        ->and(min(array_filter(array_column($plan, 1))))->toBeGreaterThanOrEqual(30)
        ->and(max(array_column($plan, 1)))->toBeLessThanOrEqual(120)
        ->and(DemoCommand::plan(37))->toHaveCount(37)
        ->and(DemoCommand::plan(1))->toBe([['completed', 0]]);
});

it('dispatches the burst and a batch to the default Horizon queue', function (): void {
    demoBatchTable();

    $code = Artisan::call('horizon-observability:demo', ['--jobs' => 50]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Dispatched 50 sample jobs to redis/default: 33 complete, 5 fail, 5 fail once and retry, 2 release once, 5 are delayed by 30 to 120 s, plus a batch of 10.')
        ->and(demoQueue())->toBe(['ready' => 55, 'delayed' => 5])
        ->and(DB::connection('testing')->table('job_batches')->value('name'))->toBe('Demo import');
});

it('gives the outcomes it promises when a worker runs them', function (): void {
    Sleep::fake();
    // The queue's clock: the retries and the release come due 1 s after their first attempt, so on a wall clock a slow
    // first worker run (parallel load) would already run them again. Only travel() moves the frozen clock.
    $this->freezeTime();
    Artisan::call('horizon-observability:demo', ['--jobs' => 20]);

    $this->runWorker();

    // First attempts: 13 complete, 2 fail, 2 throw once (retried), 1 releases itself; the batch was skipped.
    expect(demoAttempts())->toBe(['completed' => 13, 'failed' => 2, 'released' => 1, 'retried' => 2])
        ->and(demoQueue())->toBe(['ready' => 0, 'delayed' => 5]);

    $this->travel(5)->seconds();
    $this->runWorker();

    // The retries and the release run again and complete; the 2 delayed jobs wait 30 s or more.
    expect(demoAttempts())->toBe(['completed' => 16, 'failed' => 2, 'released' => 1, 'retried' => 2])
        ->and(demoQueue())->toBe(['ready' => 0, 'delayed' => 2]);

    $this->travel(125)->seconds();
    $this->runWorker();

    expect(demoAttempts()['completed'])->toBe(18)
        ->and(demoQueue())->toBe(['ready' => 0, 'delayed' => 0]);
});

it('names the jobs Demo\\<name>', function (): void {
    expect((new DemoJob('ResizeImage', 'completed', 10))->displayName())->toBe('Demo\\ResizeImage')
        ->and((new DemoJob('ResizeImage', 'retried', 10))->tries)->toBe(2)
        ->and((new DemoJob('ResizeImage', 'failed', 10))->tries)->toBe(1)
        ->and(static fn (): DemoJob => new DemoJob('X', 'exploded', 1))->toThrow(InvalidArgumentException::class);
});

it('is safe to run twice', function (): void {
    expect(Artisan::call('horizon-observability:demo', ['--jobs' => 10]))->toBe(0)
        ->and(Artisan::call('horizon-observability:demo', ['--jobs' => 10]))->toBe(0)
        ->and(array_sum(demoQueue()))->toBe(20);
});

it('refuses in production', function (): void {
    $this->app['env'] = 'production';

    expect(Artisan::call('horizon-observability:demo'))->toBe(1)
        ->and(Artisan::output())->toContain('refuses to run in production')
        ->and(array_sum(demoQueue()))->toBe(0);
});

it('rejects an invalid job count', function (mixed $jobs): void {
    expect(Artisan::call('horizon-observability:demo', ['--jobs' => $jobs]))->toBe(1)
        ->and(Artisan::output())->toContain('--jobs must be an integer from 1 to 1000.');
})->with([0, 1001, 'many', '-5', '2.5']);

it('needs a Horizon queue on a redis connection', function (): void {
    config()->set('horizon.environments', ['production' => ['supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'maxProcesses' => 1]]]);

    expect(Artisan::call('horizon-observability:demo'))->toBe(1)
        ->and(Artisan::output())->toContain('Horizon processes no redis queue in the environment "testing"');
});

it('prefers the default queue connection among the Horizon queues', function (): void {
    config()->set('queue.connections.other', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'other', 'retry_after' => 90]);
    config()->set('queue.default', 'other');
    config()->set('horizon.environments.testing', [
        'supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'maxProcesses' => 1],
        'supervisor-2' => ['connection' => 'other', 'queue' => ['emails'], 'maxProcesses' => 1],
    ]);

    Artisan::call('horizon-observability:demo', ['--jobs' => 3]);

    expect(Artisan::output())->toContain('to other/emails')
        ->and(demoQueue('emails')['ready'])->toBe(3);
});
