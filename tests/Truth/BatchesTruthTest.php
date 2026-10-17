<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Integration\Batches\Support\BatchTable;
use DF\HorizonObservability\Tests\TestCase;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

/*
| Exporter truth (T16): the batch families equal Laravel's own bookkeeping of real batches run by real workers
| (Bus::findBatch(), i.e. the job_batches rows), classified by the documented rules (Arch Q6.7): Cancelled when
| cancelled; Finished or Failures when finished, or when every pending job has failed (allowFailures batches never
| finish); Running otherwise. Kinds follow the default BatchKind rules (digits, dates and hex tokens stripped), an
| unnamed batch is "unnamed" and labelled by its id. The job classes and queues come from the jobs the batches
| dispatched.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

/**
 * Four batches at T: an import (3 jobs on reports, one fails, allowFailures) worked at T+5; an export of two jobs
 * on slow, not worked; a cleanup cancelled at T+5; an unnamed batch of one job on default, not worked.
 *
 * @return array{0: Scenario, 1: array<string, array{batch: Batch, kind: string, jobs: list<array{queue: string, class: string}>}>}
 */
function batchesTruthScenario(TestCase $test): array
{
    BatchTable::create();
    $scenario = Scenario::start($test);
    $batches = [];
    $dispatch = static function (string $kind, ?string $name, string $queue, array $jobs, bool $allowFailures = false) use (&$batches): Batch {
        $pending = Bus::batch($jobs)->onConnection('redis')->onQueue($queue);

        if ($name !== null) {
            $pending->name($name);
        }

        if ($allowFailures) {
            $pending->allowFailures();
        }

        $batch = $pending->dispatch();
        $batches[$batch->id] = [
            'batch' => $batch,
            'kind' => $kind,
            'jobs' => array_map(static fn (TruthJob $job): array => ['queue' => $queue, 'class' => $job->label], $jobs),
        ];

        return $batch;
    };

    $dispatch('import', 'import-2026-10-01', 'reports', [new TruthJob('Truth\\ImportRow'), new TruthJob('Truth\\ImportRow'), new TruthJob('Truth\\ImportFile', ['throw'])], allowFailures: true);
    $dispatch('export', 'export ab3f9e', 'slow', [new TruthJob('Truth\\ExportChunk'), new TruthJob('Truth\\ExportChunk')]);
    $cleanup = $dispatch('cleanup', 'cleanup', 'sms', [new TruthJob('Truth\\Cleanup')]);
    $dispatch('unnamed', null, 'default', [new TruthJob('Truth\\Orphan')]);

    $scenario->advance(5)->work('reports');
    $cleanup->cancel();
    $scenario->advance(5);

    return [$scenario, $batches];
}

/**
 * The batch families restated from Laravel's batch repository: family => labels key => value.
 *
 * @param  array<string, array{batch: Batch, kind: string, jobs: list<array{queue: string, class: string}>}>  $batches
 * @return array<string, array<string, float>>
 */
function batchesTruthExpected(array $batches, float $now): array
{
    $families = [];

    foreach ($batches as $id => ['kind' => $kind, 'jobs' => $jobs]) {
        $batch = Bus::findBatch((string) $id);
        $created = $batch->createdAt->getTimestamp();
        $status = match (true) {
            $batch->cancelledAt !== null => 'Cancelled',
            $batch->finishedAt !== null || ($batch->totalJobs > 0 && $batch->pendingJobs === $batch->failedJobs) => $batch->failedJobs > 0 ? 'Failures' : 'Finished',
            default => 'Running',
        };
        $closed = $batch->cancelledAt?->getTimestamp() ?? $batch->finishedAt?->getTimestamp() ?? ($status === 'Running' ? null : $created);
        $name = $batch->name !== '' ? $batch->name : (string) $id;
        $counts = ['total' => $batch->totalJobs, 'pending' => $batch->pendingJobs, 'failed' => $batch->failedJobs];
        $running = $status === 'Running';

        $families['queue_batches_running'][Model::key(['batch' => $kind])] = ($families['queue_batches_running'][Model::key(['batch' => $kind])] ?? 0.0) + ($running ? 1 : 0);

        foreach ($counts as $state => $count) {
            $families['queue_batch_jobs'][Model::key(['batch' => $kind, 'state' => $state])] = ($families['queue_batch_jobs'][Model::key(['batch' => $kind, 'state' => $state])] ?? 0.0) + ($running ? $count : 0);
            $families['queue_batch_job_counts'][Model::key(['batch' => $kind, 'name' => $name, 'state' => $state])] = (float) $count;
        }

        if ($running) {
            $oldest = Model::key(['batch' => $kind]);
            $families['queue_batch_oldest_age_seconds'][$oldest] = max($families['queue_batch_oldest_age_seconds'][$oldest] ?? 0.0, floor($now) - $created);
        }

        $families['queue_batch_created_timestamp_seconds'][Model::key(['batch' => $kind, 'name' => $name])] = (float) $created;

        if ($closed !== null) {
            $families['queue_batch_closed_timestamp_seconds'][Model::key(['batch' => $kind, 'name' => $name])] = (float) $closed;
        }

        $families['queue_batch_progress'][Model::key(['batch' => $kind, 'name' => $name, 'status' => $status, 'id' => (string) $id])]
            = round(($batch->totalJobs - $batch->pendingJobs) / $batch->totalJobs * 100);

        foreach ($jobs as ['queue' => $queue, 'class' => $class]) {
            $families['queue_batch_job_classes'][Model::key(['batch' => $kind, 'queue' => $queue, 'job_class' => $class])] = 1.0;
        }

        $queues = array_values(array_unique(array_column($jobs, 'queue')));
        sort($queues);
        $families['queue_batch_info'][Model::key(['batch' => $kind, 'queue' => implode(', ', $queues), 'connection' => 'redis'])] = 1.0;
    }

    foreach ($families as &$series) {
        ksort($series);
    }

    ksort($families);

    return $families;
}

it('exports the batches as Laravel keeps them, per kind and per batch', function (): void {
    [$scenario, $batches] = batchesTruthScenario($this);
    $samples = new Samples($scenario->scrape());
    $expected = batchesTruthExpected($batches, $scenario->unix());
    $actual = [];

    foreach (Metric::ofCollector('batches') as $metric) {
        if (($series = $samples->series($metric->value)) !== []) {
            $actual[$metric->value] = $series;
        }
    }

    ksort($actual);

    expect($actual)->toBe($expected);

    // The scenario's own outcomes, independently of the restated rules.
    $byKind = [];

    foreach ($samples->labelSets(Metric::BatchProgress->value) as $labels) {
        $byKind[$labels['batch']] = $labels['status'];
    }

    ksort($byKind);

    expect($byKind)->toBe(['cleanup' => 'Cancelled', 'export' => 'Running', 'import' => 'Failures', 'unnamed' => 'Running'])
        ->and($samples->value(Metric::BatchJobCounts->value, ['batch' => 'import', 'name' => 'import-2026-10-01', 'state' => 'failed']))->toBe(1.0)
        ->and($samples->value(Metric::BatchesRunning->value, ['batch' => 'export']))->toBe(1.0)
        ->and($samples->value(Metric::BatchOldestAge->value, ['batch' => 'export']))->toBe(10.0);
});

it('counts every job of a running batch once in the per-kind and the per-batch families', function (): void {
    [$scenario] = batchesTruthScenario($this);
    $samples = new Samples($scenario->scrape());
    $running = [];

    foreach ($samples->labelSets(Metric::BatchProgress->value) as $labels) {
        if ($labels['status'] === 'Running') {
            $running[$labels['batch']][] = $labels['name'];
        }
    }

    foreach ($samples->sumBy(Metric::BatchesRunning->value, 'batch') as $kind => $count) {
        expect(count($running[$kind] ?? []))->toBe((int) $count, (string) $kind);

        foreach (['total', 'pending', 'failed'] as $state) {
            $perBatch = (float) array_sum(array_map(
                static fn (string $name): float => (float) $samples->value(Metric::BatchJobCounts->value, ['batch' => (string) $kind, 'name' => $name, 'state' => $state]),
                $running[$kind] ?? [],
            ));

            expect($samples->value(Metric::BatchJobs->value, ['batch' => (string) $kind, 'state' => $state]))->toBe($perBatch, $kind.' '.$state);
        }
    }
});
