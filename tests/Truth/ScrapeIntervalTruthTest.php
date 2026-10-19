<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use DF\HorizonObservability\Tests\Truth\Support\Invariants;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\Scenarios;

/*
| Exporter truth: the counters are absolute (the stored totals, never increments per scrape) and the same whatever
| the scrape interval. Ten minutes of steady load (Scenarios::timeline) are scraped every 5, 10 and 60 s (the
| supported range), each in a run of its own: every scrape equals the model at its time, the scrapes on the 60 s grid
| are identical across the intervals, so increase() over any window between them is too, every series starts at 0 and
| never decreases, and the invariants hold at every scrape.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

it('exports at every scrape what the model holds at that time', function (int $interval): void {
    [, $scrapes] = Scenarios::timeline($this, $interval);

    foreach ($scrapes as $second => ['samples' => $samples, 'jobsTotal' => $jobsTotal, 'durations' => $durations, 'waits' => $waits]) {
        $actualDurations = $samples->histograms(Metric::JobDuration->value);
        $actualWaits = $samples->histograms(Metric::JobWait->value);

        expect($samples->integers(Metric::JobsTotal->value))->toBe($jobsTotal, 'at '.$second.' s')
            ->and(array_map(static fn (array $histogram): array => [$histogram['buckets'], $histogram['count']], $actualDurations))
            ->toBe(array_map(static fn (array $histogram): array => [$histogram['buckets'], $histogram['count']], $durations), 'durations at '.$second.' s')
            ->and(array_map(static fn (array $histogram): array => [$histogram['buckets'], $histogram['count']], $actualWaits))
            ->toBe(array_map(static fn (array $histogram): array => [$histogram['buckets'], $histogram['count']], $waits), 'waits at '.$second.' s')
            ->and(Invariants::violations($samples))->toBe([], 'at '.$second.' s');

        foreach ($waits as $queue => $histogram) {
            expect($actualWaits[$queue]['sum'])->toEqualWithDelta($histogram['sum'], 1e-6, 'wait sum at '.$second.' s');
        }
    }
})->with(['5 s' => 5, '10 s' => 10, '60 s' => 60]);

it('starts every series at 0, never decreases it and ends at the total', function (int $interval): void {
    [$scenario, $scrapes] = Scenarios::timeline($this, $interval);
    $last = end($scrapes)['samples']->integers(Metric::JobsTotal->value);
    $previous = [];

    foreach ($scrapes as $second => ['samples' => $samples]) {
        foreach ($samples->integers(Metric::JobsTotal->value) as $series => $value) {
            if (! isset($previous[$series])) {
                expect($value)->toBe(0, sprintf('first sample of %s at %d s', $series, $second));
            }

            expect($value)->toBeGreaterThanOrEqual($previous[$series] ?? 0, sprintf('%s at %d s', $series, $second));
            $previous[$series] = $value;
        }
    }

    expect($last)->toBe($scenario->model->jobsTotal($scenario->unix()))
        ->and(array_sum($last))->toBe(count($scenario->model->attempts()));
})->with(['5 s' => 5, '10 s' => 10, '60 s' => 60]);

it('exports the same counters on the 60 s grid at every scrape interval', function (): void {
    $grids = [];

    foreach ([5, 10, 60] as $interval) {
        [, $scrapes] = Scenarios::timeline($this, $interval);

        foreach ($scrapes as $second => ['samples' => $samples]) {
            if ($second % 60 === 0) {
                $grids[$interval][$second] = [
                    $samples->integers(Metric::JobsTotal->value),
                    $samples->integers(Metric::JobDuration->value.'_count'),
                    $samples->integers(Metric::JobWait->value.'_count'),
                ];
            }
        }

        // A fresh storage for the next interval, as in a run of its own.
        Scenario::stop();
        RedisKeys::delete(TestCase::redisPrefix());
    }

    expect($grids[5])->toBe($grids[60])
        ->and($grids[10])->toBe($grids[60])
        ->and(array_keys($grids[60]))->toBe(range(0, 660, 60));
});
