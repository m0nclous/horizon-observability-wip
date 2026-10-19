<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Truth\Support\HorizonTruth;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;

/*
| Exporter truth: the horizon families equal what Horizon's own repositories hold (HorizonTruth restates the rules
| from MasterSupervisorRepository and SupervisorRepository): the status, the processes per supervisor and pool summed
| over every master, the largest minimum and maximum, the settings, every queue a supervisor works with the pool that
| works it (horizon_supervisor_queues: PromQL cannot split the pool label of a balance=false pool into its queues),
| the wait thresholds and the links. The records are written exactly as Horizon writes them (HorizonState); a real
| Horizon is in HorizonProcessTruthTest.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

/**
 * The horizon families of a scrape, without horizon_info.
 *
 * @return array<string, array<string, float>>
 */
function horizonTruthScraped(Samples $samples): array
{
    $families = [];

    foreach (Metric::ofCollector('horizon') as $metric) {
        if ($metric !== Metric::HorizonInfo && ($series = $samples->series($metric->value)) !== []) {
            $families[$metric->value] = $series;
        }
    }

    return $families;
}

function horizonTruthExpected(): array
{
    return app(HorizonTruth::class)->expected();
}

/**
 * Two masters on two hosts: host-a runs the three workbench supervisors (auto, auto with two queues, a balance=false
 * pool of mail and sms), host-b a second supervisor-default with other process counts and limits.
 */
function horizonTruthTwoHosts(string $statusB = 'running'): void
{
    HorizonState::using(app())
        ->master('host-a', 'running', ['host-a:supervisor-default', 'host-a:supervisor-slow', 'host-a:supervisor-pool'])
        ->supervisor('host-a:supervisor-default', HorizonState::options('host-a:supervisor-default', queue: 'default', maxProcesses: 3), ['redis:default' => 3])
        ->supervisor('host-a:supervisor-slow', HorizonState::options('host-a:supervisor-slow', queue: 'slow,reports', maxProcesses: 4), ['redis:slow' => 1, 'redis:reports' => 2])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'mail,sms', balance: false, maxProcesses: 2), ['redis:mail,sms' => 2])
        ->master('host-b', $statusB, ['host-b:supervisor-default'], pid: 2000)
        ->supervisor('host-b:supervisor-default', HorizonState::options('host-b:supervisor-default', queue: 'default', minProcesses: 2, maxProcesses: 5), ['redis:default' => 2], pid: 2001);
}

it('exports the supervisors of every master as Horizon holds them', function (): void {
    $scenario = Scenario::start($this);
    horizonTruthTwoHosts();
    $actual = horizonTruthScraped(new Samples($scenario->scrape()));

    expect($actual)->toBe(horizonTruthExpected())
        // The scenario's own numbers: supervisor-default runs 3 + 2 processes on two hosts, limits 2..5.
        ->and($actual[Metric::HorizonStatus->value])->toBe(['' => 1.0])
        ->and($actual[Metric::SupervisorProcesses->value][Model::key(['supervisor' => 'supervisor-default', 'queue' => 'default'])])->toBe(5.0)
        ->and($actual[Metric::SupervisorMinProcesses->value][Model::key(['supervisor' => 'supervisor-default'])])->toBe(2.0)
        ->and($actual[Metric::SupervisorMaxProcesses->value][Model::key(['supervisor' => 'supervisor-default'])])->toBe(5.0)
        ->and($actual[Metric::SupervisorInfo->value])->toBe([
            Model::key(['supervisor' => 'supervisor-default', 'balance' => 'auto', 'connection' => 'redis']) => 1.0,
            Model::key(['supervisor' => 'supervisor-slow', 'balance' => 'auto', 'connection' => 'redis']) => 1.0,
            Model::key(['supervisor' => 'supervisor-pool', 'balance' => 'off', 'connection' => 'redis']) => 1.0,
        ]);
});

it('maps the queues of a balance=false pool to its supervisor and its pool (#5)', function (): void {
    $scenario = Scenario::start($this);
    horizonTruthTwoHosts();
    $samples = new Samples($scenario->scrape());

    expect($samples->series(Metric::SupervisorQueues->value))->toBe([
        Model::key(['supervisor' => 'supervisor-default', 'queue' => 'default', 'pool' => 'default']) => 1.0,
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'mail', 'pool' => 'mail,sms']) => 1.0,
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'sms', 'pool' => 'mail,sms']) => 1.0,
        Model::key(['supervisor' => 'supervisor-slow', 'queue' => 'reports', 'pool' => 'reports']) => 1.0,
        Model::key(['supervisor' => 'supervisor-slow', 'queue' => 'slow', 'pool' => 'slow']) => 1.0,
    ]);

    // Every pool label is a queue label of horizon_supervisor_processes of the same supervisor, and every queue the
    // queues collector reports belongs to a supervisor: the dashboard's join finds a row and the processes per queue.
    $pools = [];

    foreach ($samples->labelSets(Metric::SupervisorProcesses->value) as $labels) {
        $pools[$labels['supervisor'].'|'.$labels['queue']] = true;
    }

    $mapped = [];

    foreach ($samples->labelSets(Metric::SupervisorQueues->value) as $labels) {
        expect($pools)->toHaveKey($labels['supervisor'].'|'.$labels['pool']);
        $mapped[$labels['queue']] = true;
    }

    foreach ($samples->labelSets(Metric::QueuePendingJobs->value) as $labels) {
        expect($mapped)->toHaveKey($labels['queue']);
    }
});

it('maps numeric queue names and a balance=false option that repeats a queue as Horizon labels their pools', function (): void {
    // Numeric queue names are integer array keys in PHP; Horizon's single pool takes the option as it is.
    $scenario = Scenario::start($this);
    HorizonState::using(app())
        ->master('host-a', 'running', ['host-a:supervisor-shards', 'host-a:supervisor-zero', 'host-a:supervisor-pool'])
        ->supervisor('host-a:supervisor-shards', HorizonState::options('host-a:supervisor-shards', queue: '1,2,7'), ['redis:1' => 1, 'redis:2' => 2, 'redis:7' => 1])
        ->supervisor('host-a:supervisor-zero', HorizonState::options('host-a:supervisor-zero', queue: '0,5', balance: false), ['redis:0,5' => 2])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'mail,sms,mail', balance: false), ['redis:mail,sms,mail' => 2])
        ->master('host-b', 'running', ['host-b:supervisor-shards'], pid: 2000)
        ->supervisor('host-b:supervisor-shards', HorizonState::options('host-b:supervisor-shards', queue: '1,2,7'), ['redis:1' => 2, 'redis:2' => 1, 'redis:7' => 1], pid: 2001);
    $samples = new Samples($scenario->scrape());

    expect(horizonTruthScraped($samples))->toBe(horizonTruthExpected())
        ->and($samples->series(Metric::SupervisorQueues->value))->toBe([
            Model::key(['supervisor' => 'supervisor-zero', 'queue' => '0', 'pool' => '0,5']) => 1.0,
            Model::key(['supervisor' => 'supervisor-zero', 'queue' => '5', 'pool' => '0,5']) => 1.0,
            Model::key(['supervisor' => 'supervisor-shards', 'queue' => '1', 'pool' => '1']) => 1.0,
            Model::key(['supervisor' => 'supervisor-shards', 'queue' => '2', 'pool' => '2']) => 1.0,
            Model::key(['supervisor' => 'supervisor-shards', 'queue' => '7', 'pool' => '7']) => 1.0,
            Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'mail', 'pool' => 'mail,sms,mail']) => 1.0,
            Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'sms', 'pool' => 'mail,sms,mail']) => 1.0,
        ])
        ->and($samples->value(Metric::SupervisorProcesses->value, ['supervisor' => 'supervisor-shards', 'queue' => '1']))->toBe(3.0);
});

it('maps the queues of a queue option with spaces to the pools Horizon labels with them', function (): void {
    // 'mail, sms': Horizon splits the option of a balancing supervisor at the commas without trimming, so the pool
    // of sms and its processes are labelled ' sms'; the queue is sms, as the job lists and Supervisors split it.
    $scenario = Scenario::start($this);
    HorizonState::using(app())
        ->master('host-a', 'running', ['host-a:supervisor-spaced', 'host-a:supervisor-pool'])
        ->supervisor('host-a:supervisor-spaced', HorizonState::options('host-a:supervisor-spaced', queue: 'mail, sms'), ['redis:mail' => 1, 'redis: sms' => 2])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'reports, slow', balance: false), ['redis:reports, slow' => 3]);
    $samples = new Samples($scenario->scrape());

    expect(horizonTruthScraped($samples))->toBe(horizonTruthExpected())
        ->and($samples->series(Metric::SupervisorQueues->value))->toBe([
            Model::key(['supervisor' => 'supervisor-spaced', 'queue' => 'sms', 'pool' => ' sms']) => 1.0,
            Model::key(['supervisor' => 'supervisor-spaced', 'queue' => 'mail', 'pool' => 'mail']) => 1.0,
            Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'reports', 'pool' => 'reports, slow']) => 1.0,
            Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'slow', 'pool' => 'reports, slow']) => 1.0,
        ])
        ->and($samples->value(Metric::SupervisorProcesses->value, ['supervisor' => 'supervisor-spaced', 'queue' => ' sms']))->toBe(2.0);
});

it('maps a queue to every pool that works it while a deploy changes the queue options', function (): void {
    // A rolling deploy: the old master still runs the old options while the new one runs the new ones. The pool of
    // supervisor-pool gains push (balance=false, mail,sms -> mail,sms,push), supervisor-slow turns its pool into
    // balanced queues (balance=false slow,reports -> auto). Both masters' processes work the common queues, so each
    // queue has a series per pool and the processes per queue (the dashboard's join, Truth README decision 2) add up.
    $scenario = Scenario::start($this);
    HorizonState::using(app())
        ->master('host-a', 'running', ['host-a:supervisor-pool', 'host-a:supervisor-slow'])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'mail,sms', balance: false), ['redis:mail,sms' => 2])
        ->supervisor('host-a:supervisor-slow', HorizonState::options('host-a:supervisor-slow', queue: 'slow,reports', balance: false), ['redis:slow,reports' => 4])
        ->master('host-b', 'running', ['host-b:supervisor-pool', 'host-b:supervisor-slow'], pid: 2000)
        ->supervisor('host-b:supervisor-pool', HorizonState::options('host-b:supervisor-pool', queue: 'mail,sms,push', balance: false), ['redis:mail,sms,push' => 3], pid: 2001)
        ->supervisor('host-b:supervisor-slow', HorizonState::options('host-b:supervisor-slow', queue: 'slow,reports'), ['redis:slow' => 1, 'redis:reports' => 2], pid: 2002);
    $samples = new Samples($scenario->scrape());
    $processes = $samples->series(Metric::SupervisorProcesses->value);
    $perQueue = [];

    foreach ($samples->labelSets(Metric::SupervisorQueues->value) as $labels) {
        $key = $labels['supervisor'].' '.$labels['queue'];
        $perQueue[$key] = ($perQueue[$key] ?? 0.0) + ($processes[Model::key(['supervisor' => $labels['supervisor'], 'queue' => $labels['pool']])] ?? 0.0);
    }

    ksort($perQueue);
    $map = [
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'mail', 'pool' => 'mail,sms']) => 1.0,
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'mail', 'pool' => 'mail,sms,push']) => 1.0,
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'push', 'pool' => 'mail,sms,push']) => 1.0,
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'sms', 'pool' => 'mail,sms']) => 1.0,
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'sms', 'pool' => 'mail,sms,push']) => 1.0,
        Model::key(['supervisor' => 'supervisor-slow', 'queue' => 'reports', 'pool' => 'reports']) => 1.0,
        Model::key(['supervisor' => 'supervisor-slow', 'queue' => 'reports', 'pool' => 'slow,reports']) => 1.0,
        Model::key(['supervisor' => 'supervisor-slow', 'queue' => 'slow', 'pool' => 'slow']) => 1.0,
        Model::key(['supervisor' => 'supervisor-slow', 'queue' => 'slow', 'pool' => 'slow,reports']) => 1.0,
    ];
    ksort($map);

    expect(horizonTruthScraped($samples))->toBe(horizonTruthExpected())
        ->and($samples->series(Metric::SupervisorQueues->value))->toBe($map)
        ->and($perQueue)->toBe([
            'supervisor-pool mail' => 5.0,
            'supervisor-pool push' => 3.0,
            'supervisor-pool sms' => 5.0,
            'supervisor-slow reports' => 6.0,
            'supervisor-slow slow' => 5.0,
        ]);
});

it('exports the status of the masters: running, paused, none', function (string $state, float $status): void {
    $scenario = Scenario::start($this);

    if ($state !== 'none') {
        horizonTruthTwoHosts($state);
    }

    $samples = new Samples($scenario->scrape());

    expect($samples->value(Metric::HorizonStatus->value))->toBe($status)
        ->and(horizonTruthScraped($samples))->toBe(horizonTruthExpected());
})->with([
    'two running masters' => ['running', 1.0],
    'one paused master' => ['paused', 0.0],
    'no master' => ['none', -1.0],
]);

it('exports the wait thresholds of the configured queues and omits the disabled ones', function (): void {
    config()->set('horizon.waits', ['redis:default' => 10, 'redis:slow' => 30, 'redis:sms' => 0]);
    $scenario = Scenario::start($this);
    $samples = new Samples($scenario->scrape());

    expect($samples->series(Metric::WaitThreshold->value))->toBe(horizonTruthExpected()['horizon_wait_threshold_seconds'])
        ->and($samples->sumBy(Metric::WaitThreshold->value, 'queue'))->toBe(['default' => 10.0, 'mail' => 60.0, 'reports' => 60.0, 'slow' => 30.0]);
});

it('exports the links of the configured Horizon and text-to-speech URLs', function (): void {
    config()->set('horizon-observability.horizon_url', 'https://ops.example.com/horizon/');
    config()->set('horizon-observability.grafana.tts_url', 'https://tts.example.com/speak/');
    $scenario = Scenario::start($this);

    expect((new Samples($scenario->scrape()))->series(Metric::HorizonInfo->value))->toBe([
        Model::key(['url' => 'https://ops.example.com/horizon', 'api_path' => '/horizon/observability', 'tts_url' => 'https://tts.example.com/speak']) => 1.0,
    ]);
});
