<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\InvalidMetric;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\TextFormat;
use DF\HorizonObservability\Tests\Support\Exposition;

/*
| TextFormat: escaping, 1e17 precision, NaN/+-Inf, integral floats, le spelling, family and sample order,
| empty namespace, empty families omitted.
*/

function textFormatRender(array $families, string $prefix = 'ho_'): string
{
    return (new TextFormat)->render($families, $prefix);
}

function textFormatGauge(Metric $metric, int|float $value, string $queue = 'default'): MetricFamily
{
    return MetricFamily::of($metric)->set(['connection' => 'redis', 'queue' => $queue], $value);
}

it('renders HELP, TYPE and samples in the text format', function (): void {
    $text = textFormatRender([
        MetricFamily::of(Metric::HorizonStatus)->set([], 1),
        MetricFamily::of(Metric::JobsTotal)
            ->set(['connection' => 'redis', 'queue' => 'default', 'job_class' => 'App\\Jobs\\SendInvoice', 'status' => 'completed'], 42),
    ]);

    expect($text)->toBe(implode("\n", [
        '# HELP ho_horizon_status Horizon status: 1 running, 0 paused (at least one master supervisor paused), -1 no master supervisor.',
        '# TYPE ho_horizon_status gauge',
        'ho_horizon_status 1',
        '# HELP ho_queue_jobs_total Finished job attempts by outcome: completed, released, retried, failed, timed_out; a new series reads 0 for its first 90 seconds.',
        '# TYPE ho_queue_jobs_total counter',
        'ho_queue_jobs_total{connection="redis",queue="default",job_class="App\\\\Jobs\\\\SendInvoice",status="completed"} 42',
        '',
    ]));
});

it('escapes HELP texts and label values', function (): void {
    expect(TextFormat::escapeHelp("back\\slash\nnewline \"quoted\""))->toBe('back\\\\slash\\nnewline "quoted"')
        ->and(TextFormat::escapeLabel("back\\slash\nnewline \"quoted\""))->toBe('back\\\\slash\\nnewline \\"quoted\\"')
        ->and(textFormatRender([MetricFamily::of(Metric::JobClasses)->set(['job_class' => 'App\\Jobs\\Say "hi"'], 1)]))
        ->toContain('ho_queue_job_classes{job_class="App\\\\Jobs\\\\Say \\"hi\\""} 1');
});

it('keeps 17 significant digits where a (string) cast would lose them', function (): void {
    expect(TextFormat::number(123456789012345678.0))->toBe('1.2345678901234568E+17')
        ->and(textFormatRender([textFormatGauge(Metric::QueuePendingJobs, 123456789012345678.0)]))
        ->toContain('ho_queue_pending_jobs{connection="redis",queue="default"} 1.2345678901234568E+17')
        ->and((float) TextFormat::number(123456789012345678.0))->toBe(123456789012345678.0);
});

it('prints special values', function (): void {
    $text = textFormatRender([
        textFormatGauge(Metric::QueuePendingJobs, NAN, 'a'),
        textFormatGauge(Metric::QueueDelayedJobs, INF, 'a'),
        textFormatGauge(Metric::QueueDelayedDueJobs, -INF, 'a'),
    ]);

    expect($text)->toContain('ho_queue_pending_jobs{connection="redis",queue="a"} NaN')
        ->and($text)->toContain('ho_queue_delayed_jobs{connection="redis",queue="a"} +Inf')
        ->and($text)->toContain('ho_queue_delayed_due_jobs{connection="redis",queue="a"} -Inf');
});

it('prints integers and integral floats below 2^53 as integers, other floats in shortest form', function (int|float $value, string $expected): void {
    expect(TextFormat::number($value))->toBe($expected)
        ->and((float) TextFormat::number($value))->toBe((float) $value);
})->with([
    'int' => [42, '42'],
    'negative int' => [-1, '-1'],
    'int max' => [PHP_INT_MAX, '9223372036854775807'],
    'integral float' => [10.0, '10'],
    'negative zero' => [-0.0, '0'],
    'large integral float' => [9007199254740991.0, '9007199254740991'],
    'integral float at 2^53' => [9007199254740992.0, '9007199254740992.0'],
    'tenth' => [0.1, '0.1'],
    'two and a half' => [2.5, '2.5'],
    'third' => [1 / 3, '0.3333333333333333'],
    'sum of tenths' => [0.1 + 0.2, '0.30000000000000004'],
    'small' => [0.00001, '1.0E-5'],
    'unix time' => [1792201716.123, '1792201716.123'],
]);

it('does not depend on the serialize_precision ini', function (): void {
    $previous = ini_get('serialize_precision');
    ini_set('serialize_precision', '17');

    try {
        $number = TextFormat::number(0.1);
        $text = textFormatRender([textFormatGauge(Metric::QueuePendingJobs, 0.1)]);
        $after = ini_get('serialize_precision');
    } finally {
        ini_set('serialize_precision', (string) $previous);
    }

    expect($number)->toBe('0.1')
        ->and($text)->toContain('} 0.1'."\n")
        ->and($after)->toBe('17');
});

it('keeps the legacy le spelling', function (): void {
    $text = textFormatRender([MetricFamily::of(Metric::JobWait)->observe(
        ['connection' => 'redis', 'queue' => 'default'],
        ['0.05' => 1, '1.0' => 2, '2.5' => 3, '60' => 4, '1e3' => 5, '+Inf' => 6],
        12.5,
    )]);

    expect($text)->toContain(implode("\n", [
        '# TYPE ho_queue_job_wait_seconds histogram',
        'ho_queue_job_wait_seconds_bucket{connection="redis",queue="default",le="0.05"} 1',
        'ho_queue_job_wait_seconds_bucket{connection="redis",queue="default",le="1"} 2',
        'ho_queue_job_wait_seconds_bucket{connection="redis",queue="default",le="2.5"} 3',
        'ho_queue_job_wait_seconds_bucket{connection="redis",queue="default",le="60"} 4',
        'ho_queue_job_wait_seconds_bucket{connection="redis",queue="default",le="1000"} 5',
        'ho_queue_job_wait_seconds_bucket{connection="redis",queue="default",le="+Inf"} 6',
        'ho_queue_job_wait_seconds_sum{connection="redis",queue="default"} 12.5',
        'ho_queue_job_wait_seconds_count{connection="redis",queue="default"} 6',
    ]))
        ->and(TextFormat::le('+Inf'))->toBe('+Inf')
        ->and(TextFormat::le('INF'))->toBe('INF')
        ->and(TextFormat::le('0.25'))->toBe('0.25')
        ->and(TextFormat::le('3600.0'))->toBe('3600');
});

it('renders families in catalog order with the self-metrics last', function (): void {
    $text = textFormatRender([
        MetricFamily::of(Metric::ConfigProblems)->set([], 0),
        MetricFamily::of(Metric::CollectorSuccess)->set(['collector' => 'horizon'], 1),
        MetricFamily::of(Metric::BatchesRunning)->set(['batch' => 'export'], 2),
        MetricFamily::of(Metric::AttemptsDropped)->set([], 0),
        MetricFamily::of(Metric::JobsTotal)->set(['connection' => 'redis', 'queue' => 'q', 'job_class' => 'J', 'status' => 'failed'], 1),
        textFormatGauge(Metric::QueuePendingJobs, 3),
        MetricFamily::of(Metric::HorizonStatus)->set([], 1),
    ]);

    expect(Exposition::parse($text)->families())->toBe([
        'ho_horizon_status',
        'ho_queue_pending_jobs',
        'ho_queue_jobs_total',
        'ho_exporter_attempts_dropped_total',
        'ho_queue_batches_running',
        'ho_exporter_collector_success',
        'ho_exporter_config_problems',
    ]);
});

it('sorts the series of a family by label values in catalog label order', function (): void {
    $family = MetricFamily::of(Metric::SupervisorProcesses)
        ->set(['supervisor' => 'supervisor-slow', 'queue' => 'slow'], 2)
        ->set(['supervisor' => 'supervisor-default', 'queue' => 'default'], 3)
        ->set(['supervisor' => 'supervisor-slow', 'queue' => 'reports'], 1)
        ->set(['supervisor' => 'supervisor', 'queue' => 'z'], 4)
        ->set(['supervisor' => 'supervisor-pool', 'queue' => '10'], 5)
        ->set(['supervisor' => 'supervisor-pool', 'queue' => '9'], 6);

    $lines = array_values(array_filter(explode("\n", textFormatRender([$family])), static fn (string $line): bool => $line !== '' && $line[0] !== '#'));

    expect($lines)->toBe([
        'ho_horizon_supervisor_processes{supervisor="supervisor",queue="z"} 4',
        'ho_horizon_supervisor_processes{supervisor="supervisor-default",queue="default"} 3',
        'ho_horizon_supervisor_processes{supervisor="supervisor-pool",queue="10"} 5',
        'ho_horizon_supervisor_processes{supervisor="supervisor-pool",queue="9"} 6',
        'ho_horizon_supervisor_processes{supervisor="supervisor-slow",queue="reports"} 1',
        'ho_horizon_supervisor_processes{supervisor="supervisor-slow",queue="slow"} 2',
    ]);
});

it('keeps each histogram series together: buckets ascending, then sum and count', function (): void {
    $family = MetricFamily::of(Metric::JobDuration)
        ->observe(['connection' => 'redis', 'queue' => 'slow', 'job_class' => 'B'], ['1' => 1, '+Inf' => 2], 3.5)
        ->observe(['connection' => 'redis', 'queue' => 'default', 'job_class' => 'A'], ['1' => 0, '+Inf' => 1], 1.25);

    $lines = array_values(array_filter(explode("\n", textFormatRender([$family])), static fn (string $line): bool => $line !== '' && $line[0] !== '#'));

    expect($lines)->toBe([
        'ho_queue_job_duration_seconds_bucket{connection="redis",queue="default",job_class="A",le="1"} 0',
        'ho_queue_job_duration_seconds_bucket{connection="redis",queue="default",job_class="A",le="+Inf"} 1',
        'ho_queue_job_duration_seconds_sum{connection="redis",queue="default",job_class="A"} 1.25',
        'ho_queue_job_duration_seconds_count{connection="redis",queue="default",job_class="A"} 1',
        'ho_queue_job_duration_seconds_bucket{connection="redis",queue="slow",job_class="B",le="1"} 1',
        'ho_queue_job_duration_seconds_bucket{connection="redis",queue="slow",job_class="B",le="+Inf"} 2',
        'ho_queue_job_duration_seconds_sum{connection="redis",queue="slow",job_class="B"} 3.5',
        'ho_queue_job_duration_seconds_count{connection="redis",queue="slow",job_class="B"} 2',
    ]);
});

it('adds no prefix for an empty namespace', function (): void {
    expect(textFormatRender([MetricFamily::of(Metric::HorizonStatus)->set([], -1)], ''))->toBe(implode("\n", [
        '# HELP horizon_status Horizon status: 1 running, 0 paused (at least one master supervisor paused), -1 no master supervisor.',
        '# TYPE horizon_status gauge',
        'horizon_status -1',
        '',
    ]))->and(textFormatRender([MetricFamily::of(Metric::HorizonStatus)->set([], -1)], 'office_'))->toContain("\noffice_horizon_status -1\n");
});

it('omits empty families and ends with exactly one newline', function (): void {
    $text = textFormatRender([MetricFamily::of(Metric::QueueJobsPending), MetricFamily::of(Metric::HorizonStatus)->set([], 1), MetricFamily::of(Metric::BatchInfo)]);

    expect($text)->not->toContain('queue_jobs_pending')
        ->and($text)->not->toContain('batch_info')
        ->and($text)->toEndWith("ho_horizon_status 1\n")
        ->and($text)->not->toEndWith("\n\n")
        ->and(textFormatRender([]))->toBe('')
        ->and(textFormatRender([MetricFamily::of(Metric::HorizonStatus)]))->toBe('');
});

it('leaves out labels with an empty value', function (): void {
    expect(textFormatRender([MetricFamily::of(Metric::HorizonInfo)->set(['url' => 'http://localhost/horizon', 'api_path' => '/horizon/observability', 'tts_url' => ''], 1)]))
        ->toContain("\nho_horizon_info{url=\"http://localhost/horizon\",api_path=\"/horizon/observability\"} 1\n");
});

it('accepts any iterable of families', function (): void {
    $generator = (static function (): Generator {
        yield MetricFamily::of(Metric::HorizonStatus)->set([], 1);
    })();

    expect(textFormatRender([...$generator]))->toContain('ho_horizon_status 1')
        ->and((new TextFormat)->render((static function (): Generator {
            yield MetricFamily::of(Metric::HorizonStatus)->set([], 0);
        })(), 'ho_'))->toContain('ho_horizon_status 0');
});

it('rejects a family given twice', function (): void {
    textFormatRender([MetricFamily::of(Metric::HorizonStatus)->set([], 1), MetricFamily::of(Metric::HorizonStatus)->set([], 0)]);
})->throws(InvalidMetric::class, 'rendered twice');

it('renders output the exposition parser round-trips', function (): void {
    $text = textFormatRender([
        MetricFamily::of(Metric::JobClasses)->set(['job_class' => 'App\\Jobs\\A "quoted"'], 1)->set(['job_class' => 'Closure (routes/web.php)'], 1),
        textFormatGauge(Metric::QueueOldestPendingJobAge, 17.25),
    ]);
    $exposition = Exposition::parse($text);

    expect($exposition->value('ho_queue_job_classes', ['job_class' => 'App\\Jobs\\A "quoted"']))->toBe(1.0)
        ->and($exposition->value('ho_queue_oldest_pending_job_age_seconds', ['connection' => 'redis', 'queue' => 'default']))->toBe(17.25)
        ->and($exposition->types)->toBe(['ho_queue_oldest_pending_job_age_seconds' => 'gauge', 'ho_queue_job_classes' => 'gauge']);
});
