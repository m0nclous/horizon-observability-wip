<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Metrics\Exporter;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Support\Exposition;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Unit\Metrics\Support\FakeCollector;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

/*
| C1 and C5 (Arch Q1.3, Appendix H): a failing source never turns the scrape into a 5xx. The response stays 200, the
| failing collector reports exporter_collector_success 0 and the other collectors keep their data.
|
| The collectors of T05 and T07 are stubs here, so the failing sources are driven by test collectors that read
| exactly what the real ones read: Horizon's repositories and a reader client (C5), the job_batches table (C1).
*/

const ISOLATION_TOKEN = 'isolation-token-0123456789abcdef';

/**
 * @param  array<string, FakeCollector>  $collectors
 */
function isolatedScrape(array $collectors): Exposition
{
    app()->instance(Exporter::class, new Exporter(app(), null, $collectors));

    $response = test()->get('/horizon/observability/metrics', ['Authorization' => 'Bearer '.ISOLATION_TOKEN])->assertOk();

    return Exposition::parse((string) $response->getContent());
}

beforeEach(function (): void {
    config()->set('horizon-observability.token', ISOLATION_TOKEN);
    $this->app->forgetInstance(Config::class);
});

it('missing batches table keeps 200', function (): void {
    // collectors.batches = true requires batches: a missing table is a collector failure on purpose (Arch Q6.7).
    config()->set('horizon-observability.collectors.batches', true);
    $this->app->forgetInstance(Config::class);
    $log = new LogRecorder;
    $this->app->instance('log', $log);

    $exposition = isolatedScrape([
        'horizon' => new FakeCollector('horizon', static fn (): array => [MetricFamily::of(Metric::HorizonStatus)->set([], 1)]),
        'batches' => new FakeCollector('batches', static fn (): array => [
            MetricFamily::of(Metric::BatchesRunning)->set(['batch' => 'export'], DB::table('job_batches')->whereNull('finished_at')->count()),
        ]),
    ]);

    expect($exposition->value('ho_exporter_collector_success', ['collector' => 'batches']))->toBe(0.0)
        ->and($exposition->series('ho_queue_batches_running'))->toBe([])
        ->and($exposition->value('ho_horizon_status'))->toBe(1.0)
        ->and($exposition->value('ho_exporter_collector_success', ['collector' => 'horizon']))->toBe(1.0)
        ->and($log->messages('warning')[0] ?? '')->toStartWith('horizon-observability: collector:batches failed: QueryException: ')
        ->and($log->messages('warning')[0] ?? '')->toContain('job_batches');
});

it('runs no batches collector in auto mode without batching', function (): void {
    // collectors.batches = null (auto): no table, so the collector is not run and has no self-metric (not a failure).
    $batches = new FakeCollector('batches', static fn (): array => [MetricFamily::of(Metric::BatchesRunning)->set(['batch' => 'export'], 1)]);

    $exposition = isolatedScrape(['batches' => $batches]);

    expect($exposition->value('ho_exporter_collector_success', ['collector' => 'batches']))->toBeNull()
        ->and($batches->runs)->toBe(0)
        ->and($exposition->value('ho_exporter_collector_success', ['collector' => 'horizon']))->toBe(1.0);
});

it('redis down keeps 200 with collector_success=0', function (): void {
    // Every Redis connection points at a closed port, as during a Redis outage.
    foreach (['default', 'horizon'] as $connection) {
        config()->set('database.redis.'.$connection.'.port', 1);
        config()->set('database.redis.'.$connection.'.max_retries', 0);
    }

    foreach (['redis', MasterSupervisorRepository::class, SupervisorRepository::class, RedisClients::class, Supervisors::class] as $abstract) {
        $this->app->forgetInstance($abstract);
    }

    $log = new LogRecorder;
    $this->app->instance('log', $log);

    $exposition = isolatedScrape([
        // What HorizonCollector reads: Horizon's own repositories on the app's horizon connection.
        'horizon' => new FakeCollector('horizon', static fn (): array => [
            MetricFamily::of(Metric::HorizonStatus)->set([], app(Supervisors::class)->status()),
        ]),
        // What QueueCollector reads: the queue through a short-timeout reader client.
        'queues' => new FakeCollector('queues', static fn (): array => [
            MetricFamily::of(Metric::QueuePendingJobs)->set(['connection' => 'redis', 'queue' => 'default'], (int) app(RedisClients::class)->reader('default')->command('llen', ['queues:default'])),
        ]),
        'job_classes' => new FakeCollector('job_classes', static fn (): array => [MetricFamily::of(Metric::JobClasses)->set(['job_class' => 'App\\Jobs\\A'], 1)]),
    ]);

    expect($exposition->value('ho_exporter_collector_success', ['collector' => 'horizon']))->toBe(0.0)
        ->and($exposition->value('ho_exporter_collector_success', ['collector' => 'queues']))->toBe(0.0)
        ->and($exposition->series('ho_horizon_status'))->toBe([])
        ->and($exposition->series('ho_queue_pending_jobs'))->toBe([])
        // The collectors that do not need the failing source keep working.
        ->and($exposition->value('ho_exporter_collector_success', ['collector' => 'job_classes']))->toBe(1.0)
        ->and($exposition->value('ho_queue_job_classes', ['job_class' => 'App\\Jobs\\A']))->toBe(1.0)
        ->and($exposition->series('ho_exporter_build_info'))->toHaveCount(1)
        ->and($log->messages('warning'))->toHaveCount(2)
        ->and($log->messages('warning')[0])->toStartWith('horizon-observability: collector:horizon failed: ')
        ->and($log->messages('warning')[1])->toStartWith('horizon-observability: collector:queues failed: ');
});
