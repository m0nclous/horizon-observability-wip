<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\HistogramSnapshot;

/*
| HistogramSnapshot: non-cumulative storage to cumulative buckets of the configured bounds.
*/

it('makes the stored buckets cumulative with every configured bound and +Inf as the count', function (): void {
    $cumulative = HistogramSnapshot::cumulative(['0.1' => 2, '1' => 3, '+Inf' => 1], [0.1, 0.5, 1.0, 5.0]);

    expect($cumulative)->toBe(['0.1' => 2, '0.5' => 2, '1' => 5, '5' => 5, '+Inf' => 6])
        ->and(array_key_last($cumulative))->toBe('+Inf')
        ->and($cumulative['+Inf'])->toBe(2 + 3 + 1);
});

it('gives zeros for an empty series', function (): void {
    expect(HistogramSnapshot::cumulative([], [0.5, 2.5]))->toBe(['0.5' => 0, '2.5' => 0, '+Inf' => 0]);
});

it('folds a bound that is no longer configured into the next one, or +Inf (changed buckets)', function (): void {
    $stored = ['0.05' => 1, '0.75' => 4, '10' => 2, '60' => 3];

    expect(HistogramSnapshot::cumulative($stored, [0.5, 1.0, 30.0]))->toBe(['0.5' => 1, '1' => 5, '30' => 7, '+Inf' => 10]);
});

it('keeps the history of a removed bound and starts a new bound empty', function (): void {
    $before = HistogramSnapshot::cumulative(['1' => 4, '5' => 6], [1.0, 5.0]);
    $after = HistogramSnapshot::cumulative(['1' => 4, '5' => 6], [1.0, 2.0, 10.0]);

    expect($before)->toBe(['1' => 4, '5' => 10, '+Inf' => 10])
        ->and($after)->toBe(['1' => 4, '2' => 4, '10' => 10, '+Inf' => 10]);
});

it('ignores keys that are not bounds and negative counts', function (): void {
    expect(HistogramSnapshot::cumulative(['abc' => 5, '' => 1, 'NaN' => 2, '1' => -3, '0.5' => 2], [1.0]))->toBe(['1' => 2, '+Inf' => 2]);
});

it('sorts and deduplicates the bounds and drops non-finite ones', function (): void {
    expect(HistogramSnapshot::cumulative(['2' => 1], [5.0, 1.0, 5.0, INF, NAN]))->toBe(['1' => 0, '5' => 1, '+Inf' => 1]);
});

it('spells le like the pilot: integers without a decimal, others in shortest form', function (float $bound, string $le): void {
    expect(HistogramSnapshot::label($bound))->toBe($le);
})->with([
    [1.0, '1'],
    [60.0, '60'],
    [3600.0, '3600'],
    [0.05, '0.05'],
    [2.5, '2.5'],
    [0.1 + 0.2, '0.30000000000000004'],
    [INF, '+Inf'],
]);

it('spells le the same whatever serialize_precision is', function (): void {
    $previous = ini_set('serialize_precision', '17');

    try {
        expect(array_map(HistogramSnapshot::label(...), [0.05, 0.005, 2.5, 1.0E-5, 0.1 + 0.2]))
            ->toBe(['0.05', '0.005', '2.5', '1.0E-5', '0.30000000000000004'])
            ->and(HistogramSnapshot::labels([0.05, 0.1, 1.0]))->toBe('0.05,0.1,1')
            ->and(HistogramSnapshot::bucket(0.07, [0.05, 0.1]))->toBe('0.1')
            ->and(HistogramSnapshot::cumulative(['0.1' => 2], [0.05, 0.1]))->toBe(['0.05' => 0, '0.1' => 2, '+Inf' => 2]);
    } finally {
        ini_set('serialize_precision', $previous === false ? '-1' : $previous);
    }
});

it('picks the stored bucket of an observation', function (float $value, string $le): void {
    expect(HistogramSnapshot::bucket($value, [0.05, 0.1, 1.0, 60.0]))->toBe($le);
})->with([
    [0.0, '0.05'],
    [0.05, '0.05'],
    [0.050001, '0.1'],
    [1.0, '1'],
    [59.9, '60'],
    [60.1, '+Inf'],
    [NAN, '+Inf'],
]);

it('lists the bounds as the comma-separated le values of record_attempt.lua', function (): void {
    expect(HistogramSnapshot::labels([10.0, 0.05, 2.5, 1.0]))->toBe('0.05,1,2.5,10');
});
