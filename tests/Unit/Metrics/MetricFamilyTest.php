<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\InvalidMetric;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\Sample;

it('builds gauge and counter samples in catalog label order', function (): void {
    $family = MetricFamily::of(Metric::QueuePendingJobs)
        ->set(['queue' => 'default', 'connection' => 'redis'], 7)
        ->set(['connection' => 'redis', 'queue' => 'slow'], 0.5);

    expect($family->metric())->toBe(Metric::QueuePendingJobs)
        ->and($family->isEmpty())->toBeFalse()
        ->and($family->samples())->toEqual([
            new Sample('', ['connection' => 'redis', 'queue' => 'default'], 7),
            new Sample('', ['connection' => 'redis', 'queue' => 'slow'], 0.5),
        ])
        ->and(array_keys($family->samples()[0]->labels))->toBe(['connection', 'queue']);
});

it('is empty until a sample is added', function (): void {
    expect(MetricFamily::of(Metric::HorizonStatus)->isEmpty())->toBeTrue()
        ->and(MetricFamily::of(Metric::HorizonStatus)->set([], -1)->samples())->toEqual([new Sample('', [], -1)]);
});

it('sanitises label values', function (): void {
    $family = MetricFamily::of(Metric::JobClasses)->set(['job_class' => "App\\Jobs\\Bad\x1fName\n"], 1);

    expect($family->samples()[0]->labels['job_class'])->toBe('App\\Jobs\\BadName');
});

it('rejects a label set that does not match the catalog', function (array $labels): void {
    MetricFamily::of(Metric::QueuePendingJobs)->set($labels, 1);
})->with([
    'missing label' => [['connection' => 'redis']],
    'unknown label' => [['connection' => 'redis', 'queue' => 'default', 'job' => 'x']],
    'no labels' => [[]],
])->throws(InvalidMetric::class);

it('reserves le for histogram buckets', function (): void {
    MetricFamily::of(Metric::HorizonStatus)->set(['le' => '1'], 1);
})->throws(InvalidMetric::class, 'reserved');

it('rejects non-string label values', function (): void {
    MetricFamily::of(Metric::JobClasses)->set(['job_class' => 5], 1);
})->throws(InvalidMetric::class, 'must be a string');

it('rejects a duplicate label set', function (): void {
    MetricFamily::of(Metric::QueuePendingJobs)
        ->set(['connection' => 'redis', 'queue' => 'default'], 1)
        ->set(['queue' => 'default', 'connection' => 'redis'], 2);
})->throws(InvalidMetric::class, 'duplicate label set');

it('rejects values that collide after sanitising', function (): void {
    MetricFamily::of(Metric::JobClasses)
        ->set(['job_class' => 'App\\Jobs\\A'], 1)
        ->set(['job_class' => "App\\Jobs\\A\n"], 1);
})->throws(InvalidMetric::class, 'duplicate label set');

it('rejects negative and NaN counters but not gauges', function (): void {
    expect(MetricFamily::of(Metric::HorizonStatus)->set([], -1)->samples())->toHaveCount(1)
        ->and(MetricFamily::of(Metric::QueuePendingJobs)->set(['connection' => 'redis', 'queue' => 'q'], NAN)->samples())->toHaveCount(1)
        ->and(fn (): MetricFamily => MetricFamily::of(Metric::AttemptsDropped)->set([], -1))->toThrow(InvalidMetric::class, 'counter')
        ->and(fn (): MetricFamily => MetricFamily::of(Metric::AttemptsDropped)->set([], NAN))->toThrow(InvalidMetric::class, 'counter');
});

it('uses set() only for counters and gauges', function (): void {
    MetricFamily::of(Metric::JobWait)->set(['connection' => 'redis', 'queue' => 'default'], 1);
})->throws(InvalidMetric::class, 'histogram');

it('uses observe() only for histograms', function (): void {
    MetricFamily::of(Metric::QueuePendingJobs)->observe(['connection' => 'redis', 'queue' => 'default'], ['+Inf' => 1], 1.0);
})->throws(InvalidMetric::class, 'use set()');

it('builds histogram buckets, sum and count', function (): void {
    $family = MetricFamily::of(Metric::JobWait)->observe(
        ['queue' => 'default', 'connection' => 'redis'],
        ['0.5' => 1, '1' => 3, '2.5' => 3, '+Inf' => 4],
        6.25,
    );

    expect($family->samples())->toEqual([
        new Sample('_bucket', ['connection' => 'redis', 'queue' => 'default', 'le' => '0.5'], 1),
        new Sample('_bucket', ['connection' => 'redis', 'queue' => 'default', 'le' => '1'], 3),
        new Sample('_bucket', ['connection' => 'redis', 'queue' => 'default', 'le' => '2.5'], 3),
        new Sample('_bucket', ['connection' => 'redis', 'queue' => 'default', 'le' => '+Inf'], 4),
        new Sample('_sum', ['connection' => 'redis', 'queue' => 'default'], 6.25),
        new Sample('_count', ['connection' => 'redis', 'queue' => 'default'], 4),
    ]);
});

it('accepts integer bucket keys (PHP turns "1" into 1)', function (): void {
    $family = MetricFamily::of(Metric::JobWait)->observe(['connection' => 'redis', 'queue' => 'q'], [1 => 2, 60 => 2, '+Inf' => 2], 3.0);

    expect(array_column(array_map(static fn (Sample $sample): array => $sample->labels, $family->samples()), 'le'))->toBe(['1', '60', '+Inf']);
});

it('rejects malformed histograms', function (array $buckets): void {
    MetricFamily::of(Metric::JobWait)->observe(['connection' => 'redis', 'queue' => 'q'], $buckets, 1.0);
})->with([
    'no buckets' => [[]],
    'no +Inf' => [['1' => 1, '5' => 2]],
    '+Inf not last' => [['+Inf' => 2, '5' => 2]],
    'descending bounds' => [['5' => 1, '1' => 1, '+Inf' => 1]],
    'decreasing counts' => [['1' => 3, '5' => 2, '+Inf' => 3]],
    'non-numeric bound' => [['fast' => 1, '+Inf' => 1]],
    'non-integer count' => [['1' => 1.5, '+Inf' => 2]],
    'negative count' => [['1' => -1, '+Inf' => 0]],
])->throws(InvalidMetric::class);
