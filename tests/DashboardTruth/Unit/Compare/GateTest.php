<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\DashboardTruth\Cases\Plan;
use DF\HorizonObservability\Tests\DashboardTruth\Compare\Assertion;
use DF\HorizonObservability\Tests\DashboardTruth\Compare\Checks;
use DF\HorizonObservability\Tests\DashboardTruth\Compare\Report;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\CaseDef;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Category;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Observation;

/*
| The gates of the assert stage on synthetic artifacts (T17, design sections 5.2 and 11.2): the runtime gate fails
| when #8 A or B made no assertion, when a range target was not compared at its replay steps and when every
| expectation was trivial; a URL value that is not an option of its variable fails as case setup (unless the case is
| custom); a `$` left in a sent query fails as case setup; an aborted request that the frontend re-sent is no failure.
*/

pest()->group('grafana');

afterEach(function (): void {
    foreach ($GLOBALS['dashboardTruthGateDirs'] ?? [] as $dir) {
        array_map(unlink(...), glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    $GLOBALS['dashboardTruthGateDirs'] = [];
});

/**
 * A run directory with a plan of S01 (off) and its report.
 */
function dashboardTruthGateDir(): string
{
    $dir = sys_get_temp_dir().'/ho-t17-gate-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $GLOBALS['dashboardTruthGateDirs'][] = $dir;
    $case = new CaseDef('S01', 'slice', Mode::Off);
    file_put_contents($dir.'/plan.json', json_encode([
        'schema' => 1,
        'run' => 'unitgate',
        'runDir' => $dir,
        'mutant' => null,
        'routerLog' => $dir.'/router.log',
        'grafanas' => ['13' => ['url' => 'http://127.0.0.1:1', 'version' => '13.2.4']],
        'renders' => ['off' => $dir.'/off.json'],
        'cases' => [[
            'case' => $case->toArray(),
            'id' => 'S01',
            'uid' => 't17-off',
            'path' => '/d/t17-off/?'.$case->query(),
            'fakeNow' => 1740966000000,
            'timezone' => 'UTC',
            'width' => 1920,
            'height' => 5000,
            'rows' => [],
            'panels' => [['id' => 8, 'type' => 'stat', 'title' => 'Completed', 'refIds' => ['A', 'B'], 'repeat' => null, 'queried' => true, 'datasources' => ['prometheus']]],
        ]],
    ]));

    return $dir;
}

/**
 * @return list<Assertion>
 */
function dashboardTruthCompleteAssertions(): array
{
    $assertion = static fn (string $refId, string $level, string $source, bool $nonTrivial = true): Assertion => new Assertion('S01', '13', 8, $level === 'dom' ? null : $refId, $level, $source, true, nonTrivial: $nonTrivial);

    return [
        $assertion('A', 'frame', 'grid'),
        $assertion('A', 'frame', 'replay 10s'),
        $assertion('A', 'frame', 'replay 20s'),
        $assertion('B', 'frame', 'grid'),
        $assertion('-', 'dom', 'dom'),
    ];
}

it('passes the runtime gate when every promised assertion happened', function (): void {
    $dir = dashboardTruthGateDir();
    $report = Report::open($dir, fresh: true);
    $report->record('S01', '13', dashboardTruthCompleteAssertions());

    expect($report->gate(Plan::read($dir.'/plan.json'), [['S01', '13']]))->toBe([]);
});

it('fails the runtime gate when #8 A or B made no assertion', function (string $refId): void {
    $dir = dashboardTruthGateDir();
    $report = Report::open($dir, fresh: true);
    $report->record('S01', '13', array_values(array_filter(dashboardTruthCompleteAssertions(), static fn (Assertion $one): bool => $one->refId !== $refId)));

    expect($report->gate(Plan::read($dir.'/plan.json'), [['S01', '13']]))->toBe([sprintf('S01 on Grafana 13: #8 %s made no frame assertion', $refId)]);
})->with(['A', 'B']);

it('fails the runtime gate when one replay step is missing', function (): void {
    $dir = dashboardTruthGateDir();
    $report = Report::open($dir, fresh: true);
    $report->record('S01', '13', array_values(array_filter(dashboardTruthCompleteAssertions(), static fn (Assertion $one): bool => $one->source !== 'replay 20s')));

    expect($report->gate(Plan::read($dir.'/plan.json'), [['S01', '13']]))->toBe(['S01 on Grafana 13: #8 A was not compared on the grid and at both replay steps']);
});

it('fails the runtime gate without the replays, with trivial expectations only, and without the DOM', function (): void {
    $dir = dashboardTruthGateDir();
    $report = Report::open($dir, fresh: true);
    $assertions = dashboardTruthCompleteAssertions();
    $report->record('S01', '13', [
        new Assertion('S01', '13', 8, 'A', 'frame', 'grid', true, nonTrivial: false),
        $assertions[3],
    ]);

    expect($report->gate(Plan::read($dir.'/plan.json'), [['S01', '13']]))->toBe([
        'S01 on Grafana 13: #8 A was not compared on the grid and at both replay steps',
        'S01 on Grafana 13: #8 A had only trivial expectations (empty, all 0 or a dash)',
        'S01 on Grafana 13: #8 made no DOM assertion',
    ]);
});

/**
 * An observation of S01 with the queue variable's options, the requests given.
 *
 * @param  list<array<string, mixed>>  $requests
 */
function dashboardTruthObservation(array $requests = []): Observation
{
    $path = tempnam(sys_get_temp_dir(), 'ho-t17-observation');
    file_put_contents($path, json_encode([
        'schema' => 1, 'case' => 'S01', 'grafana' => '13', 'grafanaVersion' => '13.2.4', 'url' => 'http://x/d/t17-off/', 'fakeNow' => 1740966000000,
        'complete' => true, 'incomplete' => [], 'rows' => [], 'released' => ['atMs' => 1, 'requests' => count($requests)],
        'requests' => $requests,
        'variables' => [
            ['name' => 'queue', 'type' => 'query', 'value' => ['$__all'], 'text' => ['All'], 'options' => [['text' => 'default', 'value' => 'default'], ['text' => 'slow', 'value' => 'slow']]],
            ['name' => 'scrape_interval', 'type' => 'textbox', 'value' => '10s', 'text' => '10s', 'options' => []],
        ],
        'panels' => [], 'dom' => (object) [], 'router' => [], 'errors' => [], 'timing' => (object) [],
    ]));

    try {
        return Observation::read($path);
    } finally {
        unlink($path);
    }
}

/**
 * @return array<string, mixed>
 */
function dashboardTruthRequest(int $seq, string $expr, ?string $error = null): array
{
    return [
        'seq' => $seq, 'panelId' => '8', 'panelTitle' => 'Completed', 'pluginId' => 'stat', 'dashboardUid' => 't17-off', 'dsType' => 'prometheus', 'url' => '/api/ds/query',
        'body' => ['from' => '1740962400000', 'to' => '1740966000000', 'queries' => [['refId' => 'A', 'expr' => $expr]]],
        'status' => $error === null ? 200 : null,
        'response' => $error === null ? ['results' => ['A' => ['status' => 200, 'frames' => []]]] : null,
        'error' => $error,
    ];
}

$planned = ['panels' => [['id' => 8, 'type' => 'stat', 'title' => 'Completed', 'refIds' => ['A'], 'repeat' => null, 'queried' => true, 'datasources' => ['prometheus']]]];

it('fails a URL value that is not an option as case setup', function () use ($planned): void {
    $failures = Checks::run(new CaseDef('S02', 'slice', Mode::Off, vars: ['queue' => ['no-such-queue']]), $planned, dashboardTruthObservation());

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->category)->toBe(Category::CaseSetup)
        ->and($failures[0]->expected)->toBe('var-queue=no-such-queue is an option of queue')
        ->and($failures[0]->actual)->toBe('options: default, slow')
        ->and(Checks::run(new CaseDef('S02', 'slice', Mode::Off, vars: ['queue' => ['slow']]), $planned, dashboardTruthObservation()))->toBe([])
        ->and(Checks::run(new CaseDef('S02', 'slice', Mode::Off, vars: ['queue' => ['no-such-queue']], custom: true), $planned, dashboardTruthObservation()))->toBe([]);
});

it('fails a variable left in a sent query as case setup, and allows the backend macros', function () use ($planned): void {
    $leftover = Checks::run(new CaseDef('S01', 'slice', Mode::Off), $planned, dashboardTruthObservation([dashboardTruthRequest(1, 'sum(x{queue=~"$queue"}[$__interval])')]));
    $macros = Checks::run(new CaseDef('S01', 'slice', Mode::Off), $planned, dashboardTruthObservation([dashboardTruthRequest(1, 'label_replace(sum(x[$__interval$__interval_ms:$__interval]), "a", "$1", "b", "(.*)") * $__range_s')]));

    expect($leftover)->toHaveCount(1)
        ->and($leftover[0]->category)->toBe(Category::CaseSetup)
        ->and($leftover[0]->actual)->toBe('expr holds $queue')
        ->and(Checks::leftover(['expr' => 'x[$__interval${__interval_ms}ms]']))->toBe('expr holds ${__interval_ms')
        ->and($macros)->toBe([]);
});

it('accepts an aborted request that the frontend re-sent, and fails one it did not', function () use ($planned): void {
    $resent = Checks::run(new CaseDef('S01', 'slice', Mode::Off), $planned, dashboardTruthObservation([dashboardTruthRequest(1, 'x', 'net::ERR_ABORTED'), dashboardTruthRequest(2, 'x')]));
    $lost = Checks::run(new CaseDef('S01', 'slice', Mode::Off), $planned, dashboardTruthObservation([dashboardTruthRequest(1, 'x'), dashboardTruthRequest(2, 'x', 'net::ERR_ABORTED')]));

    expect($resent)->toBe([])
        ->and($lost)->toHaveCount(1)
        ->and($lost[0]->category)->toBe(Category::Infrastructure);
});
