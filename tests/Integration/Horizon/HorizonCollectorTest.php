<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Collectors\HorizonCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\Queues\Support\CollectedSamples;
use DF\HorizonObservability\Tests\Support\HorizonState;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

/*
| The horizon collector (Arch Q1.3, Q4, C5, C14, C17): the status from Horizon's masters, the supervisors from its
| repository, the wait thresholds of the workbench topology (redis:default 10 s, redis:slow 30 s, the rest 60 s)
| and horizon_info. Every source fails on its own.
*/

pest()->group('redis');

function horizonCollectorRun(): CollectedSamples
{
    return CollectedSamples::collect(app(HorizonCollector::class), new ScrapeContext(CarbonImmutable::now(), 8.0));
}

/**
 * A running master with the three workbench supervisors, as Horizon writes them.
 */
function horizonCollectorWorkbench(string $master = 'host-a1b2', string $status = 'running', int $defaultProcesses = 3, int $defaultMax = 3): HorizonState
{
    return HorizonState::using(app())
        ->master($master, $status, [$master.':supervisor-default', $master.':supervisor-slow', $master.':supervisor-pool'])
        ->supervisor($master.':supervisor-default', HorizonState::options($master.':supervisor-default', queue: 'default', maxProcesses: $defaultMax), ['redis:default' => $defaultProcesses])
        ->supervisor($master.':supervisor-slow', HorizonState::options($master.':supervisor-slow', queue: 'slow,reports', minProcesses: 1, maxProcesses: 2), ['redis:slow' => 1, 'redis:reports' => 1])
        ->supervisor($master.':supervisor-pool', HorizonState::options($master.':supervisor-pool', queue: 'mail,sms', balance: false, maxProcesses: 2), ['redis:mail,sms' => 2]);
}

beforeEach(function (): void {
    config()->set('app.url', 'https://app.example.com');
    config()->set('horizon.domain');
    config()->set('horizon.path', 'horizon');
});

it('exports the status, the supervisors, the wait thresholds and the links', function (): void {
    horizonCollectorWorkbench();

    $samples = horizonCollectorRun();

    expect($samples->families)->toBe(array_map(static fn (Metric $metric): string => $metric->value, Metric::ofCollector('horizon')))
        ->and($samples->failures)->toBe([])
        ->and($samples->value('horizon_status'))->toBe(1)
        ->and($samples->series('horizon_supervisor_processes'))->toEqualCanonicalizing([
            'queue="default",supervisor="supervisor-default"' => 3,
            'queue="slow",supervisor="supervisor-slow"' => 1,
            'queue="reports",supervisor="supervisor-slow"' => 1,
            'queue="mail,sms",supervisor="supervisor-pool"' => 2,
        ])
        ->and($samples->series('horizon_supervisor_min_processes'))->toEqualCanonicalizing([
            'supervisor="supervisor-default"' => 1, 'supervisor="supervisor-slow"' => 1, 'supervisor="supervisor-pool"' => 1,
        ])
        ->and($samples->series('horizon_supervisor_max_processes'))->toEqualCanonicalizing([
            'supervisor="supervisor-default"' => 3, 'supervisor="supervisor-slow"' => 2, 'supervisor="supervisor-pool"' => 2,
        ])
        ->and($samples->series('horizon_supervisor_info'))->toEqualCanonicalizing([
            'balance="auto",connection="redis",supervisor="supervisor-default"' => 1,
            'balance="auto",connection="redis",supervisor="supervisor-slow"' => 1,
            'balance="off",connection="redis",supervisor="supervisor-pool"' => 1,
        ])
        ->and($samples->series('horizon_wait_threshold_seconds'))->toBe([
            'connection="redis",queue="default"' => 10,
            'connection="redis",queue="slow"' => 30,
            'connection="redis",queue="reports"' => 60,
            'connection="redis",queue="mail"' => 60,
            'connection="redis",queue="sms"' => 60,
        ])
        ->and($samples->series('horizon_info'))->toBe([
            'api_path="/horizon/observability",tts_url="",url="https://app.example.com/horizon"' => 1,
        ]);
});

it('reports 1 running, 0 when a master is paused and -1 without a master', function (): void {
    expect(horizonCollectorRun()->value('horizon_status'))->toBe(-1);

    HorizonState::using(app())->master('host-a', 'running');
    expect(horizonCollectorRun()->value('horizon_status'))->toBe(1);

    HorizonState::using(app())->master('host-b', 'paused');
    expect(horizonCollectorRun()->value('horizon_status'))->toBe(0);
});

it('exports no supervisor series while Horizon is down', function (): void {
    $samples = horizonCollectorRun();

    expect($samples->families)->toBe(['horizon_status', 'horizon_wait_threshold_seconds', 'horizon_info'])
        ->and($samples->failures)->toBe([]);
});

test('balance=false supervisors keep the pooled queue label (C14)', function (): void {
    // Horizon runs one pool for a balance=false supervisor and keys its processes "redis:mail,sms"; the metrics keep
    // that label (the job lists split it, see SupervisorSnapshot::$queues).
    horizonCollectorWorkbench();

    expect(horizonCollectorRun()->value('horizon_supervisor_processes', ['supervisor' => 'supervisor-pool', 'queue' => 'mail,sms']))->toBe(2)
        ->and(collect(app(Supervisors::class)->all())->firstWhere('name', 'supervisor-pool')?->queues)->toBe(['mail', 'sms']);
});

it('sums a supervisor that runs under several masters', function (): void {
    // During a deploy the old and the new master both run supervisor-default.
    horizonCollectorWorkbench('host-a', defaultProcesses: 2, defaultMax: 3);
    horizonCollectorWorkbench('host-b', defaultProcesses: 1, defaultMax: 5);
    HorizonState::using(app())->supervisor('host-b:supervisor-default', HorizonState::options('host-b:supervisor-default', queue: 'default', balance: 'simple', maxProcesses: 5), ['redis:default' => 1]);

    $samples = horizonCollectorRun();

    expect($samples->value('horizon_supervisor_processes', ['supervisor' => 'supervisor-default', 'queue' => 'default']))->toBe(3)
        ->and($samples->value('horizon_supervisor_processes', ['supervisor' => 'supervisor-pool', 'queue' => 'mail,sms']))->toBe(4)
        ->and($samples->value('horizon_supervisor_max_processes', ['supervisor' => 'supervisor-default']))->toBe(5)
        // One info series per supervisor, from the first master by name.
        ->and($samples->series('horizon_supervisor_info'))->toHaveCount(3)
        ->and($samples->value('horizon_supervisor_info', ['supervisor' => 'supervisor-default', 'balance' => 'auto', 'connection' => 'redis']))->toBe(1);
});

it('omits the wait threshold of a queue whose check is disabled', function (): void {
    config()->set('horizon.waits', ['redis:default' => 0, 'redis:slow' => 45]);

    $series = horizonCollectorRun()->series('horizon_wait_threshold_seconds');

    expect($series)->not->toHaveKey('connection="redis",queue="default"')
        ->and($series['connection="redis",queue="slow"'])->toBe(45)
        ->and($series['connection="redis",queue="sms"'])->toBe(60);
});

it('exports the links of horizon_info', function (array $config, string $info): void {
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    app()->forgetInstance(Config::class);

    expect(array_keys(horizonCollectorRun()->series('horizon_info')))->toBe([$info]);
})->with([
    'browser speech' => [['horizon-observability.grafana.tts_url' => 'browser'], 'api_path="/horizon/observability",tts_url="browser",url="https://app.example.com/horizon"'],
    'speech server' => [['horizon-observability.grafana.tts_url' => 'https://tts.example.com/'], 'api_path="/horizon/observability",tts_url="https://tts.example.com",url="https://app.example.com/horizon"'],
    'custom paths and domain' => [
        ['horizon.path' => 'ops', 'horizon.domain' => 'horizon.example.com', 'horizon-observability.path' => 'metrics/horizon'],
        'api_path="/metrics/horizon",tts_url="",url="https://horizon.example.com/ops"',
    ],
    'override' => [['horizon-observability.horizon_url' => 'https://ops.example.com/horizon'], 'api_path="/horizon/observability",tts_url="",url="https://ops.example.com/horizon"'],
]);

test('redis down keeps the links and the thresholds and reports the failed sources (C5)', function (): void {
    // The pilot read Horizon's status through spatie's unguarded collector and WorkloadRepository, so a Redis outage
    // turned the whole scrape into a 500. Each source fails on its own now; nothing is thrown.
    foreach (['default', 'horizon'] as $connection) {
        config()->set('database.redis.'.$connection.'.port', 1);
        config()->set('database.redis.'.$connection.'.max_retries', 0);
    }

    foreach (['redis', MasterSupervisorRepository::class, SupervisorRepository::class, RedisClients::class, Supervisors::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    $samples = horizonCollectorRun();

    expect($samples->failedSources())->toBe(['status', 'supervisors'])
        ->and($samples->families)->toBe(['horizon_wait_threshold_seconds', 'horizon_info'])
        ->and($samples->series('horizon_wait_threshold_seconds'))->toHaveCount(5);
});

it('reports a broken Horizon config as a topology failure and keeps the rest (C5)', function (): void {
    horizonCollectorWorkbench();
    config()->set('horizon.environments.testing.supervisor-default.minProcesses', 0);

    $samples = horizonCollectorRun();

    expect($samples->failedSources())->toBe(['topology'])
        ->and($samples->failures[0]['exception']->getMessage())->toContain('minProcesses] must be greater than 0')
        ->and($samples->families)->toBe(['horizon_status', 'horizon_supervisor_processes', 'horizon_supervisor_min_processes', 'horizon_supervisor_max_processes', 'horizon_supervisor_info', 'horizon_info']);
});

test('only its own catalog families, without horizon_current_processes (C17)', function (): void {
    // The pilot exported horizon_current_processes{queue} (a duplicate of sum by (queue) of the supervisor processes,
    // under a name spatie also registers); it is gone, and every family belongs to this collector.
    horizonCollectorWorkbench();

    $families = horizonCollectorRun()->families;

    expect($families)->not->toContain('horizon_current_processes')
        ->and(array_diff($families, array_map(static fn (Metric $metric): string => $metric->value, Metric::ofCollector('horizon'))))->toBe([])
        ->and(Metric::tryFrom('horizon_current_processes'))->toBeNull();
});
