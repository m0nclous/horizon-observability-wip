<?php

declare(strict_types=1);

use DF\HorizonObservability\Batches\BatchReader;
use DF\HorizonObservability\Collectors\HorizonCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Exporter;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Support\Versions;
use DF\HorizonObservability\Tests\Support\Exposition;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Unit\Metrics\Support\ExpositionFixture;
use DF\HorizonObservability\Tests\Unit\Metrics\Support\FakeClock;
use DF\HorizonObservability\Tests\Unit\Metrics\Support\FakeCollector;
use Illuminate\Http\Request;

/*
| Arch Q1.3, Q1.4 and 8.4 (Exporter with fake collectors): a throwing collector is isolated; a partial failure keeps
| its data with success 0; the budget skips the rest in order; disabled collectors are absent; self-metrics are last.
*/

/**
 * @param  array<string, mixed>  $config  horizon-observability.* overrides
 */
function exporterTestConfig(array $config): void
{
    foreach ($config as $key => $value) {
        config()->set('horizon-observability.'.$key, $value);
    }

    app()->forgetInstance(Config::class);
}

/**
 * @param  array<string, FakeCollector>  $collectors
 * @param  array<string, string>  $headers
 */
function exporterTestScrape(array $collectors, ?FakeClock $clock = null, array $headers = []): Exposition
{
    $request = Request::create('/horizon/observability/metrics');

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return Exposition::parse((new Exporter(app(), ($clock ?? new FakeClock)(...), $collectors))->scrape($request));
}

/**
 * @param  Closure(ScrapeContext): iterable<mixed>|null  $collect
 */
function exporterTestCollector(string $id, ?Closure $collect = null): FakeCollector
{
    return new FakeCollector($id, $collect ?? static fn (): array => []);
}

/**
 * @return array<string, FakeCollector>
 */
function exporterTestQuiet(): array
{
    return array_map(static fn (string $id): FakeCollector => exporterTestCollector($id), array_combine(Config::COLLECTORS, Config::COLLECTORS));
}

function exporterTestSuccess(Exposition $exposition, string $collector): ?float
{
    return $exposition->value('ho_exporter_collector_success', ['collector' => $collector]);
}

function exporterTestLog(): LogRecorder
{
    $log = new LogRecorder;
    app()->instance('log', $log);

    return $log;
}

beforeEach(function (): void {
    $this->app->instance(Versions::class, ExpositionFixture::versions());
});

it('renders the golden exposition', function (): void {
    exporterTestConfig(['collectors.batches' => true]);
    $clock = new FakeClock;

    $text = (new Exporter($this->app, $clock(...), ExpositionFixture::collectors($clock)))->scrape(Request::create('/horizon/observability/metrics'));

    expect(ExpositionFixture::normalize($text))->toBe(file_get_contents(ExpositionFixture::GOLDEN));

    $exposition = Exposition::parse($text);
    $families = array_map(static fn (Metric $metric): string => 'ho_'.$metric->value, Metric::cases());

    // Every family of the catalog, in catalog order, with its catalog TYPE.
    expect($exposition->families())->toBe($families)
        ->and(array_values($exposition->types))->toBe(array_map(static fn (Metric $metric): string => $metric->type()->value, Metric::cases()));
});

it('isolates a throwing collector', function (): void {
    $log = exporterTestLog();
    $collectors = [...exporterTestQuiet(),
        'horizon' => exporterTestCollector('horizon', static fn (): array => [MetricFamily::of(Metric::HorizonStatus)->set([], 1)]),
        'queues' => exporterTestCollector('queues', static fn (): never => throw new RuntimeException("Connection refused\nsecond line")),
        'attempts' => exporterTestCollector('attempts', static fn (): array => [MetricFamily::of(Metric::AttemptsDropped)->set([], 3)]),
    ];

    $exposition = exporterTestScrape($collectors);

    expect($exposition->value('ho_horizon_status'))->toBe(1.0)
        ->and($exposition->value('ho_exporter_attempts_dropped_total'))->toBe(3.0)
        ->and(exporterTestSuccess($exposition, 'horizon'))->toBe(1.0)
        ->and(exporterTestSuccess($exposition, 'queues'))->toBe(0.0)
        ->and(exporterTestSuccess($exposition, 'attempts'))->toBe(1.0)
        ->and(exporterTestSuccess($exposition, 'job_classes'))->toBe(1.0)
        ->and($log->messages('warning'))->toBe(['horizon-observability: collector:queues failed: RuntimeException: Connection refused']);
});

it('isolates PHP warnings turned into ErrorException (C1 shape)', function (): void {
    $collectors = [...exporterTestQuiet(),
        'batches' => exporterTestCollector('batches', static function (): array {
            $stats = [];

            return [MetricFamily::of(Metric::BatchesRunning)->set(['batch' => 'export'], $stats['kinds']['export'])];
        }),
    ];
    exporterTestConfig(['collectors.batches' => true]);

    $exposition = exporterTestScrape($collectors);

    expect(exporterTestSuccess($exposition, 'batches'))->toBe(0.0)
        ->and($exposition->series('ho_queue_batches_running'))->toBe([])
        ->and(exporterTestSuccess($exposition, 'horizon'))->toBe(1.0);
});

it('keeps the data of a partial failure but reports success 0', function (): void {
    $log = exporterTestLog();
    $collectors = [...exporterTestQuiet(),
        'queues' => exporterTestCollector('queues', static function (ScrapeContext $context): array {
            $context->reportFailure('queue:slow', new RuntimeException('NOSCRIPT'));

            return [MetricFamily::of(Metric::QueuePendingJobs)->set(['connection' => 'redis', 'queue' => 'default'], 4)];
        }),
        'attempts' => exporterTestCollector('attempts', static fn (): array => [MetricFamily::of(Metric::AttemptsDropped)->set([], 0)]),
    ];

    $exposition = exporterTestScrape($collectors);

    expect($exposition->value('ho_queue_pending_jobs', ['connection' => 'redis', 'queue' => 'default']))->toBe(4.0)
        ->and(exporterTestSuccess($exposition, 'queues'))->toBe(0.0)
        ->and(exporterTestSuccess($exposition, 'attempts'))->toBe(1.0)
        ->and($log->messages('warning'))->toBe(['horizon-observability: collector:queues:queue:slow failed: RuntimeException: NOSCRIPT']);
});

it('drops every family of a collector that fails midway', function (): void {
    $collectors = [...exporterTestQuiet(),
        'horizon' => exporterTestCollector('horizon', static function (): Generator {
            yield MetricFamily::of(Metric::HorizonStatus)->set([], 1);

            throw new RuntimeException('supervisors unreadable');
        }),
    ];

    $exposition = exporterTestScrape($collectors);

    expect($exposition->series('ho_horizon_status'))->toBe([])
        ->and(exporterTestSuccess($exposition, 'horizon'))->toBe(0.0);
});

it('fails a collector that builds an invalid family', function (Closure $collect, string $message): void {
    $log = exporterTestLog();

    $exposition = exporterTestScrape([...exporterTestQuiet(), 'horizon' => exporterTestCollector('horizon', $collect)]);

    expect(exporterTestSuccess($exposition, 'horizon'))->toBe(0.0)
        ->and($exposition->series('ho_horizon_status'))->toBe([])
        ->and($log->messages('warning')[0] ?? '')->toContain($message);
})->with([
    'label mismatch' => [static fn (): array => [MetricFamily::of(Metric::SupervisorMinProcesses)->set(['queue' => 'default'], 1)], 'expects the labels'],
    'family of another collector' => [static fn (): array => [MetricFamily::of(Metric::HorizonStatus)->set([], 1), MetricFamily::of(Metric::QueuePendingJobs)], 'a family of the queues collector'],
    'family twice' => [static fn (): array => [MetricFamily::of(Metric::HorizonStatus)->set([], 1), MetricFamily::of(Metric::HorizonStatus)->set([], 0)], 'twice'],
    'not a family' => [static fn (): array => [MetricFamily::of(Metric::HorizonStatus)->set([], 1), ['horizon_status' => 1]], 'instead of a MetricFamily'],
    'self-metric' => [static fn (): array => [MetricFamily::of(Metric::CollectorSuccess)->set(['collector' => 'horizon'], 1)], 'a family of the self collector'],
]);

it('skips the remaining collectors in order once the budget is used up', function (): void {
    $log = exporterTestLog();
    $clock = new FakeClock;
    $collectors = [...exporterTestQuiet(),
        'horizon' => exporterTestCollector('horizon', static function () use ($clock): array {
            $clock->advance(7.5);

            return [MetricFamily::of(Metric::HorizonStatus)->set([], 1)];
        }),
        'queues' => exporterTestCollector('queues', static function () use ($clock): array {
            $clock->advance(0.75);

            return [MetricFamily::of(Metric::QueuePendingJobs)->set(['connection' => 'redis', 'queue' => 'default'], 1)];
        }),
    ];
    exporterTestConfig(['collectors.batches' => true]);

    $exposition = exporterTestScrape($collectors, $clock);

    expect(exporterTestSuccess($exposition, 'horizon'))->toBe(1.0)
        ->and(exporterTestSuccess($exposition, 'queues'))->toBe(1.0)
        ->and($exposition->value('ho_queue_pending_jobs', ['connection' => 'redis', 'queue' => 'default']))->toBe(1.0)
        ->and($exposition->value('ho_exporter_collector_duration_seconds', ['collector' => 'horizon']))->toBe(7.5)
        ->and($exposition->value('ho_exporter_collector_duration_seconds', ['collector' => 'queues']))->toBe(0.75);

    foreach (['attempts', 'job_classes', 'batches'] as $skipped) {
        expect(exporterTestSuccess($exposition, $skipped))->toBe(0.0)
            ->and($exposition->value('ho_exporter_collector_duration_seconds', ['collector' => $skipped]))->toBe(0.0)
            ->and($collectors[$skipped]->runs)->toBe(0);
    }

    expect($log->messages('warning'))->toBe([
        'horizon-observability: the scrape budget of 8 s ran out before the attempts collector, which was skipped (exporter_collector_success 0); raise the scrape_timeout or check the collector durations.',
        'horizon-observability: the scrape budget of 8 s ran out before the job_classes collector, which was skipped (exporter_collector_success 0); raise the scrape_timeout or check the collector durations.',
        'horizon-observability: the scrape budget of 8 s ran out before the batches collector, which was skipped (exporter_collector_success 0); raise the scrape_timeout or check the collector durations.',
    ]);
});

it('derives the budget from X-Prometheus-Scrape-Timeout-Seconds', function (?string $header, float $budget): void {
    $request = Request::create('/horizon/observability/metrics');

    if ($header !== null) {
        $request->headers->set('X-Prometheus-Scrape-Timeout-Seconds', $header);
    }

    $horizon = exporterTestCollector('horizon');
    exporterTestScrape([...exporterTestQuiet(), 'horizon' => $horizon], new FakeClock, $header === null ? [] : ['X-Prometheus-Scrape-Timeout-Seconds' => $header]);

    expect(Exporter::budget($request))->toBe($budget)
        ->and($horizon->context?->remaining())->toBe($budget);
})->with([
    'no header' => [null, 8.0],
    'timeout above the cap' => ['10', 8.0],
    'Prometheus default' => ['8', 7.5],
    'short timeout' => ['5', 4.5],
    'fraction' => ['2.25', 1.75],
    'below the floor' => ['1.2', 1.0],
    'zero' => ['0', 1.0],
    'negative' => ['-3', 1.0],
    'padded' => [' 3 ', 2.5],
    'exponent' => ['4e0', 3.5],
    'not a number' => ['abc', 8.0],
    'infinity' => ['INF', 8.0],
    'empty' => ['', 8.0],
]);

it('runs neither disabled collectors nor their self-metrics', function (): void {
    $collectors = exporterTestQuiet();
    exporterTestConfig(['collectors.queues' => false, 'collectors.job_classes' => false, 'collectors.batches' => false]);

    $exposition = exporterTestScrape($collectors);

    expect(array_keys($exposition->series('ho_exporter_collector_success')))->toBe(['collector="attempts"', 'collector="horizon"'])
        ->and(array_keys($exposition->series('ho_exporter_collector_duration_seconds')))->toBe(['collector="attempts"', 'collector="horizon"'])
        ->and($collectors['queues']->runs)->toBe(0)
        ->and($collectors['job_classes']->runs)->toBe(0)
        ->and($collectors['batches']->runs)->toBe(0)
        ->and($collectors['horizon']->runs)->toBe(1);
});

it('runs batches in auto mode only when batching is available', function (?object $reader, ?float $success, int $runs): void {
    if ($reader !== null) {
        $this->app->instance(BatchReader::class, $reader);
    }

    $collectors = exporterTestQuiet();
    exporterTestConfig(['collectors.batches' => null]);

    $exposition = exporterTestScrape($collectors);

    expect(exporterTestSuccess($exposition, 'batches'))->toBe($success)
        ->and($collectors['batches']->runs)->toBe($runs);
})->with([
    'the container reader, no batches table' => [null, null, 0],
    'available' => [new class
    {
        public function available(): bool
        {
            return true;
        }
    }, 1.0, 1],
    'detection fails' => [new class
    {
        public function available(): bool
        {
            throw new RuntimeException('SQLSTATE[HY000]: unable to open database file');
        }
    }, 0.0, 0],
]);

it('isolates a collector that cannot be resolved', function (): void {
    $this->app->bind(HorizonCollector::class, static fn (): never => throw new RuntimeException('bad binding'));
    $log = exporterTestLog();

    // Only horizon comes from the container.
    $exposition = exporterTestScrape(array_diff_key(exporterTestQuiet(), ['horizon' => true]));

    expect(exporterTestSuccess($exposition, 'horizon'))->toBe(0.0)
        ->and(exporterTestSuccess($exposition, 'queues'))->toBe(1.0)
        ->and($log->messages('warning'))->toBe(['horizon-observability: collector:horizon failed: RuntimeException: bad binding']);
});

it('runs the container collectors by default', function (): void {
    // Bound in place of the real classes (other tasks' code that may need Redis), resolved through Exporter::COLLECTORS.
    $collectors = exporterTestQuiet();

    foreach (Exporter::COLLECTORS as $id => $class) {
        $this->app->instance($class, $collectors[$id]);
    }

    exporterTestConfig(['collectors.batches' => true]);

    $exposition = exporterTestScrape([]);

    expect(array_keys(Exporter::COLLECTORS))->toBe(Config::COLLECTORS)
        ->and($exposition->series('ho_exporter_collector_success'))->toBe([
            'collector="attempts"' => 1.0,
            'collector="batches"' => 1.0,
            'collector="horizon"' => 1.0,
            'collector="job_classes"' => 1.0,
            'collector="queues"' => 1.0,
        ])
        ->and(array_map(static fn (FakeCollector $collector): int => $collector->runs, $collectors))->toBe(array_fill_keys(Config::COLLECTORS, 1));
});

it('renders the self-metrics last', function (): void {
    exporterTestConfig(['collectors.batches' => true]);
    $clock = new FakeClock;

    $exposition = exporterTestScrape(ExpositionFixture::collectors($clock), $clock);

    expect(array_slice($exposition->families(), -4))->toBe([
        'ho_exporter_collector_success',
        'ho_exporter_collector_duration_seconds',
        'ho_exporter_build_info',
        'ho_exporter_config_problems',
    ]);
});

it('prefixes every family with the namespace', function (string $namespace, string $prefix): void {
    exporterTestConfig(['namespace' => $namespace]);

    $exposition = exporterTestScrape([...exporterTestQuiet(), 'horizon' => exporterTestCollector('horizon', static fn (): array => [MetricFamily::of(Metric::HorizonStatus)->set([], 1)])]);

    expect($exposition->families())->toBe([
        $prefix.'horizon_status',
        $prefix.'exporter_collector_success',
        $prefix.'exporter_collector_duration_seconds',
        $prefix.'exporter_build_info',
        $prefix.'exporter_config_problems',
    ]);
})->with([
    'default' => ['ho', 'ho_'],
    'office' => ['office', 'office_'],
    'trailing underscore' => ['office_', 'office_'],
    'empty' => ['', ''],
]);

it('exports the number of config problems', function (): void {
    exporterTestConfig(['namespace' => '1bad', 'exceptions' => 'everything', 'live.stream.id' => 'bad id']);

    expect(exporterTestScrape(exporterTestQuiet())->value('ho_exporter_config_problems'))->toBe(3.0);
});

it('logs the config problems on a scrape served over HTTP', function (): void {
    // Over HTTP the provider leaves the warning to the (authorised) scrape: denied requests stay free of cache I/O.
    (fn (): false => $this->isRunningInConsole = false)->call($this->app);
    $log = exporterTestLog();
    exporterTestConfig(['namespace' => '1bad']);
    app(Config::class);
    $resolved = $log->messages('warning');

    exporterTestScrape(exporterTestQuiet());

    expect($resolved)->toBe([])
        ->and($log->messages('warning'))->toBe(['horizon-observability: 1 invalid configuration values were replaced by defaults; run php artisan horizon-observability:check.']);
});

it('exports the build info', function (): void {
    $exposition = exporterTestScrape(exporterTestQuiet());

    expect($exposition->series('ho_exporter_build_info'))->toBe([
        Exposition::key(['version' => '0.1.0', 'php' => PHP_VERSION, 'laravel' => '12.40.0', 'horizon' => '5.50.1', 'redis_client' => 'predis 3.0.0']) => 1.0,
    ]);

    $this->app->instance(Versions::class, new Versions(config(), '11.23.0', static fn (): ?string => null, static fn (): ?string => null));

    expect(array_keys(exporterTestScrape(exporterTestQuiet())->series('ho_exporter_build_info'))[0])
        ->toContain('version="unknown"')
        ->toContain('horizon="unknown"')
        ->toContain('laravel="11.23.0"');
});

it('reads everything afresh on every scrape (no memo)', function (): void {
    $source = new ArrayObject(['max' => 10]);
    $horizon = exporterTestCollector('horizon', static fn (ScrapeContext $context): array => [
        MetricFamily::of(Metric::SupervisorMaxProcesses)->set(['supervisor' => 'default'], $context->remember('max', static fn (): int => $source['max'])),
    ]);
    $collectors = [...exporterTestQuiet(), 'horizon' => $horizon];
    $exporter = new Exporter($this->app, (new FakeClock)(...), $collectors);
    $request = Request::create('/horizon/observability/metrics');

    $first = Exposition::parse($exporter->scrape($request));
    $firstContext = $horizon->context;
    $source['max'] = 12;
    $second = Exposition::parse($exporter->scrape($request));

    expect($first->value('ho_horizon_supervisor_max_processes', ['supervisor' => 'default']))->toBe(10.0)
        ->and($second->value('ho_horizon_supervisor_max_processes', ['supervisor' => 'default']))->toBe(12.0)
        ->and($horizon->runs)->toBe(2)
        ->and($horizon->context)->not->toBe($firstContext);
});

it('passes the request time to the collectors', function (): void {
    $this->travelTo('2026-10-17 12:00:00.250');
    $horizon = exporterTestCollector('horizon');

    exporterTestScrape([...exporterTestQuiet(), 'horizon' => $horizon]);

    expect($horizon->context?->now()->getPreciseTimestamp(3))->toBe(1792238400250.0);
});
