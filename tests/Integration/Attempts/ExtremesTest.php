<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;

/*
| Duration extremes tolerant of 60 s scrapes. An attempt at `a` sits in the 15-second bucket b = floor(a / 15); a scrape
| at `t` merges the buckets floor(t / 15) - 9 ... floor(t / 15), so the attempt is exported by every scrape in
| [a, 15(b + 10)), more than 135 s. The pilot's 60-second windows (current + previous) kept it for about 60 s in the
| worst case: one late 60 s scrape lost it.
*/

pest()->group('redis');

beforeEach(function (): void {
    // 1 700 000 010 is a multiple of 15: the start of bucket 113 333 334.
    $this->base = 1_700_000_010;
    $this->field = static fn (string $class): string => Keys::field('redis', 'default', $class);
    $memo = [];
    $this->visible = static function (float $time) use (&$memo): array {
        $second = (int) floor($time);

        return $memo[$second] ??= Storage::store()->extremes(CarbonImmutable::createFromTimestamp($second))['max'];
    };
});

it('exports an attempt from t + 0 to t + 135 s and not from 15(b + 10) on', function (float $offset): void {
    $attempt = $this->base + $offset;
    $bucket = intdiv((int) floor($attempt), Keys::EXTREMES_BUCKET_SECONDS);
    $end = Keys::EXTREMES_BUCKET_SECONDS * ($bucket + 10);
    Storage::record(jobClass: 'App\\Jobs\\Probe', duration: 1.25, now: CarbonImmutable::createFromTimestamp($attempt));
    $field = ($this->field)('App\\Jobs\\Probe');

    foreach ([...range(0, 135), 135.0] as $delta) {
        expect(($this->visible)($attempt + $delta))->toHaveKey($field);
    }

    expect(($this->visible)($end - 0.001))->toHaveKey($field)
        ->and(($this->visible)($end))->not->toHaveKey($field)
        ->and(($this->visible)($end + 60))->not->toHaveKey($field);
})->with([
    'start of a bucket' => [0.0],
    'middle of a bucket' => [7.3],
    'end of a bucket (shortest visibility)' => [14.999],
]);

it('exports every attempt at least twice for scrape intervals of 5 to 60 s with deviations up to 7.5 s', function (int $interval): void {
    $offsets = [0.0, 3.7, 7.5, 11.2, 14.999, 15.0 + $interval / 3, 15.0 + $interval / 2];
    $phases = [0.0, 0.25, 0.5, 0.75];
    $attempts = [];

    foreach ($offsets as $index => $offset) {
        $class = 'App\\Jobs\\Probe'.$index;
        $attempts[$class] = $this->base + $offset;
        Storage::record(jobClass: $class, duration: 1.0, now: CarbonImmutable::createFromTimestamp($attempts[$class]));
    }

    mt_srand(20261017 + $interval);
    $minimum = PHP_INT_MAX;

    foreach ($attempts as $class => $attempt) {
        $field = ($this->field)($class);

        foreach ($phases as $phase) {
            $nominal = [];

            for ($time = $this->base - 2 * $interval + $phase * $interval; $time < $attempt + 170; $time += $interval) {
                $nominal[] = $time;
            }

            $middle = $attempt + 67.5;
            $patterns = [
                'none' => array_map(static fn (float $time): float => $time, $nominal),
                'early' => array_map(static fn (float $time): float => $time - 7.5, $nominal),
                'late' => array_map(static fn (float $time): float => $time + 7.5, $nominal),
                'alternating' => array_map(static fn (float $time, int $k): float => $time + ($k % 2 === 0 ? 7.5 : -7.5), $nominal, array_keys($nominal)),
                'alternating, early first' => array_map(static fn (float $time, int $k): float => $time + ($k % 2 === 0 ? -7.5 : 7.5), $nominal, array_keys($nominal)),
                // Scrapes near the start of the window come early, near its end late: the fewest inside.
                'adversarial' => array_map(static fn (float $time): float => $time + ($time < $middle ? -7.5 : 7.5), $nominal),
                'random' => array_map(static fn (float $time): float => $time + (mt_rand(-7500, 7500) / 1000), $nominal),
            ];

            foreach ($patterns as $name => $scrapes) {
                $seen = count(array_filter($scrapes, fn (float $scrape): bool => $scrape >= $attempt && array_key_exists($field, ($this->visible)($scrape))));
                $minimum = min($minimum, $seen);

                expect($seen)->toBeGreaterThanOrEqual(2, sprintf('S=%ds, attempt at +%.3fs, phase %.2f, %s: %d scrapes', $interval, $attempt - $this->base, $phase, $name, $seen));
            }
        }
    }

    expect($minimum)->toBeGreaterThanOrEqual(2);
})->with([5, 10, 15, 30, 45, 60]);

it('still exports every attempt once at 60 s scrapes with deviations up to 37 s', function (): void {
    $attempt = $this->base + 14.999;
    Storage::record(jobClass: 'App\\Jobs\\Probe', duration: 1.0, now: CarbonImmutable::createFromTimestamp($attempt));
    $field = ($this->field)('App\\Jobs\\Probe');

    foreach ([0.0, 15.0, 30.0, 45.0] as $phase) {
        $scrapes = [];

        for ($time = $this->base - 120 + $phase; $time < $attempt + 220; $time += 60) {
            $scrapes[] = $time + ($time < $attempt + 67.5 ? -37.0 : 37.0);
        }

        $seen = count(array_filter($scrapes, fn (float $scrape): bool => $scrape >= $attempt && array_key_exists($field, ($this->visible)($scrape))));

        expect($seen)->toBeGreaterThanOrEqual(1);
    }
});

it('exports the extremes through the attempts collector', function (): void {
    Storage::record(duration: 2.5, now: CarbonImmutable::createFromTimestamp($this->base + 1));
    Storage::record(duration: 0.5, now: CarbonImmutable::createFromTimestamp($this->base + 61));

    $samples = Storage::scrape(CarbonImmutable::createFromTimestamp($this->base + 136))['samples'];
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice';

    expect($samples['queue_job_duration_min_seconds'])->toBe([$series => 0.5])
        ->and($samples['queue_job_duration_max_seconds'])->toBe([$series => 2.5])
        ->and(Storage::scrape(CarbonImmutable::createFromTimestamp($this->base + 150))['samples']['queue_job_duration_max_seconds'])->toBe([$series => 0.5]);
});
