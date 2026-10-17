<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Truth\Support\HorizonTruth;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;

/*
| Exporter truth (T16): the horizon families equal what Horizon's own repositories hold (HorizonTruth restates the
| rules from MasterSupervisorRepository and SupervisorRepository): the status, the processes per supervisor and pool
| summed over every master, the largest minimum and maximum, the settings, every queue a supervisor works with the
| pool that works it (horizon_supervisor_queues, the fix of the pilot's balance=false bug in #5), the wait thresholds
| and the links. The records are written exactly as Horizon writes them (HorizonState); a real Horizon is in
| HorizonProcessTruthTest.
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
        ->and($actual['horizon_status'])->toBe(['' => 1.0])
        ->and($actual['horizon_supervisor_processes'][Model::key(['supervisor' => 'supervisor-default', 'queue' => 'default'])])->toBe(5.0)
        ->and($actual['horizon_supervisor_min_processes'][Model::key(['supervisor' => 'supervisor-default'])])->toBe(2.0)
        ->and($actual['horizon_supervisor_max_processes'][Model::key(['supervisor' => 'supervisor-default'])])->toBe(5.0);
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
