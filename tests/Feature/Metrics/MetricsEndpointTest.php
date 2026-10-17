<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Exporter;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Support\Versions;
use DF\HorizonObservability\Tests\Support\Exposition;
use DF\HorizonObservability\Tests\Unit\Metrics\Support\ExpositionFixture;
use DF\HorizonObservability\Tests\Unit\Metrics\Support\FakeClock;
use DF\HorizonObservability\Tests\Unit\Metrics\Support\FakeCollector;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
| GET {base}/metrics (Arch Q1, Q2.1, 8.4): content type, no caching, HEAD, the golden exposition over HTTP, the
| scrape timeout header, absolute counters (C3) and no foreign families (C17).
|
| Every test scrapes fake collectors (quiet by default), so it does not depend on the real collectors of T04, T05,
| T07 and T08, except "no spatie families", which also scrapes the real ones (redis group).
*/

const METRICS_ENDPOINT_TEST_TOKEN = 'metrics-endpoint-token-0123456789';

/**
 * @param  array<string, mixed>  $config  horizon-observability.* values
 */
function metricsEndpointTestConfig(array $config = []): void
{
    config()->set('horizon-observability.token', METRICS_ENDPOINT_TEST_TOKEN);

    foreach ($config as $key => $value) {
        config()->set('horizon-observability.'.$key, $value);
    }

    app()->forgetInstance(Config::class);
}

/**
 * @param  array<string, string>  $headers
 */
function metricsEndpointTestScrape(array $headers = []): TestResponse
{
    return test()->get('/horizon/observability/metrics', ['Authorization' => 'Bearer '.METRICS_ENDPOINT_TEST_TOKEN, ...$headers]);
}

beforeEach(function (): void {
    metricsEndpointTestConfig();
    $this->app->instance(Exporter::class, new Exporter($this->app, null, FakeCollector::quiet()));
});

it('serves the text format 0.0.4 and forbids caching', function (): void {
    $response = metricsEndpointTestScrape()->assertOk()
        ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    $text = (string) $response->getContent();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($text)->toEndWith("\n")
        ->and($text)->not->toEndWith("\n\n")
        ->and(Exposition::parse($text)->families())->toContain('ho_exporter_collector_success', 'ho_exporter_build_info', 'ho_exporter_config_problems');
});

it('answers HEAD with the same headers and runs no collector', function (): void {
    $collector = new FakeCollector('horizon', static fn (): array => [MetricFamily::of(Metric::HorizonStatus)->set([], 1)]);
    $this->app->instance(Exporter::class, new Exporter($this->app, null, [...FakeCollector::quiet(), 'horizon' => $collector]));

    $response = $this->call('HEAD', '/horizon/observability/metrics', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.METRICS_ENDPOINT_TEST_TOKEN])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($collector->runs)->toBe(0);

    metricsEndpointTestScrape()->assertOk();

    expect($collector->runs)->toBe(1);
});

it('renders the golden exposition over HTTP', function (): void {
    metricsEndpointTestConfig(['collectors.batches' => true]);
    $clock = new FakeClock;
    $this->app->instance(Versions::class, ExpositionFixture::versions());
    $this->app->instance(Exporter::class, new Exporter($this->app, $clock(...), ExpositionFixture::collectors($clock)));

    expect(ExpositionFixture::normalize((string) metricsEndpointTestScrape()->assertOk()->getContent()))->toBe(file_get_contents(ExpositionFixture::GOLDEN));
});

it('honours X-Prometheus-Scrape-Timeout-Seconds', function (): void {
    $clock = new FakeClock;
    $slow = static fn (string $id, float $seconds): FakeCollector => new FakeCollector($id, static function () use ($clock, $seconds): array {
        $clock->advance($seconds);

        return [];
    });
    $collectors = ['horizon' => $slow('horizon', 1.25), 'queues' => $slow('queues', 0.5), 'attempts' => $slow('attempts', 0.0), 'job_classes' => $slow('job_classes', 0.0), 'batches' => $slow('batches', 0.0)];
    metricsEndpointTestConfig(['collectors.batches' => true]);
    $this->app->instance(Exporter::class, new Exporter($this->app, $clock(...), $collectors));

    // Timeout 2 s: budget 1.5 s. horizon uses 1.25 s, queues starts in time and ends at 1.75 s, the rest is skipped.
    $exposition = Exposition::parse((string) metricsEndpointTestScrape(['X-Prometheus-Scrape-Timeout-Seconds' => '2'])->assertOk()->getContent());

    expect($exposition->series('ho_exporter_collector_success'))->toBe([
        'collector="attempts"' => 0.0,
        'collector="batches"' => 0.0,
        'collector="horizon"' => 1.0,
        'collector="job_classes"' => 0.0,
        'collector="queues"' => 1.0,
    ])->and($collectors['attempts']->runs)->toBe(0)
        ->and($collectors['batches']->runs)->toBe(0);
});

test('counters are absolute across scrapes', function (): void {
    // The legacy pilot pushed absolute totals through a counter's inc() into a persistent registry, so every scrape
    // added the total again (10, 20, 30). Nothing is kept between scrapes now: the exposition is the source's value.
    $source = new ArrayObject(['completed' => 10]);
    $attempts = new FakeCollector('attempts', static fn (): array => [
        MetricFamily::of(Metric::JobsTotal)->set(['connection' => 'redis', 'queue' => 'default', 'job_class' => 'App\\Jobs\\A', 'status' => 'completed'], $source['completed']),
        MetricFamily::of(Metric::JobDuration)->observe(['connection' => 'redis', 'queue' => 'default', 'job_class' => 'App\\Jobs\\A'], ['1' => $source['completed'], '+Inf' => $source['completed']], 0.5 * $source['completed']),
    ]);
    metricsEndpointTestConfig(['collectors.batches' => true]);
    $this->app->instance(Exporter::class, new Exporter($this->app, null, [...FakeCollector::quiet(), 'attempts' => $attempts]));
    // Any cache operation at all (the array store fires an event for each): the exporter keeps no registry.
    $cache = [];
    Event::listen('Illuminate\\Cache\\Events\\*', static function (string $event) use (&$cache): void {
        $cache[] = $event;
    });
    $labels = ['connection' => 'redis', 'queue' => 'default', 'job_class' => 'App\\Jobs\\A'];
    $read = static function () use ($labels): array {
        $exposition = Exposition::parse((string) metricsEndpointTestScrape()->assertOk()->getContent());

        return [
            $exposition->value('ho_queue_jobs_total', [...$labels, 'status' => 'completed']),
            $exposition->value('ho_queue_job_duration_seconds_count', $labels),
            $exposition->value('ho_queue_job_duration_seconds_sum', $labels),
        ];
    };

    $first = $read();
    $second = $read();
    $third = $read();
    $source['completed'] = 15;
    $fourth = $read();

    expect([$first, $second, $third, $fourth])->toBe([[10.0, 10.0, 5.0], [10.0, 10.0, 5.0], [10.0, 10.0, 5.0], [15.0, 15.0, 7.5]])
        ->and($attempts->runs)->toBe(4)
        ->and($cache)->toBe([]);
});

test('no spatie families', function (): void {
    $catalog = array_map(static fn (Metric $metric): string => 'ho_'.$metric->value, Metric::cases());

    // The real collectors (stubs until their tasks land) and the full fixture scrape.
    $this->app->forgetInstance(Exporter::class);
    $real = Exposition::parse((string) metricsEndpointTestScrape()->assertOk()->getContent());

    metricsEndpointTestConfig(['collectors.batches' => true]);
    $clock = new FakeClock;
    $this->app->instance(Exporter::class, new Exporter($this->app, $clock(...), ExpositionFixture::collectors($clock)));
    $full = Exposition::parse((string) metricsEndpointTestScrape()->assertOk()->getContent());

    foreach ([$real, $full] as $exposition) {
        $names = array_keys($exposition->samples);

        expect(array_diff($exposition->families(), $catalog))->toBe([])
            ->and(array_filter($names, static fn (string $name): bool => ! str_starts_with($name, 'ho_')))->toBe([])
            ->and(array_filter($names, static fn (string $name): bool => str_contains($name, 'horizon_current_processes')))->toBe([])
            ->and($exposition->series('ho_queue_oldest_pending_job_age'))->toBe([]);
    }

    // Histograms are real histograms, not three counter families (legacy spatie workaround).
    expect($full->types)->toMatchArray(['ho_queue_job_duration_seconds' => 'histogram', 'ho_queue_job_wait_seconds' => 'histogram'])
        ->and($full->types)->not->toHaveKey('ho_queue_job_duration_seconds_bucket')
        ->and($full->types)->not->toHaveKey('ho_queue_job_wait_seconds_count');
})->group('redis');

it('answers 200 when every collector fails', function (): void {
    metricsEndpointTestConfig(['collectors.batches' => true]);
    $failing = array_map(
        static fn (string $id): FakeCollector => new FakeCollector($id, static fn (): never => throw new RuntimeException($id.' is down')),
        array_combine(Config::COLLECTORS, Config::COLLECTORS),
    );
    $this->app->instance(Exporter::class, new Exporter($this->app, null, $failing));

    $exposition = Exposition::parse((string) metricsEndpointTestScrape()->assertOk()->getContent());

    expect($exposition->series('ho_exporter_collector_success'))->toBe([
        'collector="attempts"' => 0.0,
        'collector="batches"' => 0.0,
        'collector="horizon"' => 0.0,
        'collector="job_classes"' => 0.0,
        'collector="queues"' => 0.0,
    ])->and($exposition->series('ho_exporter_build_info'))->toHaveCount(1);
});

it('uses the configured namespace and reports config problems', function (): void {
    metricsEndpointTestConfig(['namespace' => 'office', 'exceptions' => 'verbose', 'collectors.batches' => true]);
    $this->app->instance(Exporter::class, new Exporter($this->app, null, [
        ...FakeCollector::quiet(),
        'horizon' => new FakeCollector('horizon', static fn (): array => [MetricFamily::of(Metric::HorizonStatus)->set([], 1)]),
    ]));

    $exposition = Exposition::parse((string) metricsEndpointTestScrape()->assertOk()->getContent());

    expect($exposition->families())->toBe([
        'office_horizon_status',
        'office_exporter_collector_success',
        'office_exporter_collector_duration_seconds',
        'office_exporter_build_info',
        'office_exporter_config_problems',
    ])->and($exposition->value('office_exporter_config_problems'))->toBe(1.0);
});
