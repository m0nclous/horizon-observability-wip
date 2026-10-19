<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\Scenarios;

/*
| Exporter truth: the attempt families of a scrape equal the expected-value model of a scenario with every outcome
| (completed, released, retried, failed, failed without a duration, timed out), known durations and waits, a delayed
| dispatch, a backoff and a job reserved during a scrape, all run through real queue workers. Counters and histogram
| buckets are exact; a duration sum lies within the planned durations plus Model::OVERHEAD_SECONDS per attempt; waits
| are exact on the frozen clock.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

it('exports every attempt by outcome exactly (queue_jobs_total)', function (): void {
    $scenario = Scenarios::attempts($this);
    $samples = new Samples($scenario->scrape());
    $expected = $scenario->model->jobsTotal($scenario->unix());

    expect($samples->integers(Metric::JobsTotal->value))->toBe($expected)
        // The scenario's own outcome table, independently of the model: 8 completed, 3 failed, 2 released, 3 retried, 1 timed out.
        ->and(array_sum($expected))->toBe(17)
        ->and($samples->sumBy(Metric::JobsTotal->value, 'status'))->toBe([
            'completed' => 8.0, 'failed' => 3.0, 'released' => 2.0, 'retried' => 3.0, 'timed_out' => 1.0,
        ]);
});

it('exports the duration histograms with exact buckets and counts (queue_job_duration_seconds)', function (): void {
    $scenario = Scenarios::attempts($this);
    $actual = (new Samples($scenario->scrape()))->histograms(Metric::JobDuration->value);
    $expected = $scenario->model->durations($scenario->unix());

    expect(array_keys($actual))->toBe(array_keys($expected));

    foreach ($expected as $series => $histogram) {
        expect($actual[$series]['buckets'])->toBe($histogram['buckets'], $series)
            ->and($actual[$series]['count'])->toBe($histogram['count'], $series)
            ->and($actual[$series]['sum'])->toBeGreaterThanOrEqual($histogram['sum'][0] - 1e-9, $series)
            ->and($actual[$series]['sum'])->toBeLessThanOrEqual($histogram['sum'][1], $series);
    }

    // A timed-out attempt is capped at its timeout: exactly 1 s, in the le="1" bucket.
    expect($actual['connection="redis",job_class="Truth\\\\Hang",queue="sms"'])->toBe([
        'buckets' => ['0.25' => 0, '1' => 1, '2.5' => 1, '10' => 1, '30' => 1, '60' => 1, '120' => 1, '+Inf' => 1],
        'count' => 1,
        'sum' => 1.0,
    ]);
});

it('exports the waits of first attempts exactly (queue_job_wait_seconds)', function (): void {
    $scenario = Scenarios::attempts($this);
    $actual = (new Samples($scenario->scrape()))->histograms(Metric::JobWait->value);
    $expected = $scenario->model->waits($scenario->unix());

    expect(array_keys($actual))->toBe(array_keys($expected));

    foreach ($expected as $series => $histogram) {
        expect($actual[$series]['buckets'])->toBe($histogram['buckets'], $series)
            ->and($actual[$series]['count'])->toBe($histogram['count'], $series)
            ->and($actual[$series]['sum'])->toEqualWithDelta($histogram['sum'], 1e-6, $series);
    }

    // Independently of the model: 6 first attempts on default (retries and the second attempts of released jobs
    // have no wait), Truth\Later waited from its due time T+20 until T+40.
    expect($actual['connection="redis",queue="default"']['count'])->toBe(6)
        ->and($actual['connection="redis",queue="reports"']['count'])->toBe(2);
});

it('exports the duration extremes of the last 150 seconds (queue_job_duration_min_seconds, queue_job_duration_max_seconds)', function (): void {
    $scenario = Scenarios::attempts($this);
    $samples = new Samples($scenario->scrape());
    $expected = $scenario->model->extremes($scenario->unix());
    $min = $samples->series(Metric::JobDurationMin->value);
    $max = $samples->series(Metric::JobDurationMax->value);

    expect(array_keys($min))->toBe(array_keys($expected))
        ->and(array_keys($max))->toBe(array_keys($expected));

    foreach ($expected as $series => [$minLow, $minHigh, $maxLow, $maxHigh]) {
        expect($min[$series])->toBeGreaterThanOrEqual($minLow, $series)->toBeLessThanOrEqual($minHigh, $series)
            ->and($max[$series])->toBeGreaterThanOrEqual($maxLow, $series)->toBeLessThanOrEqual($maxHigh, $series);
    }
});
