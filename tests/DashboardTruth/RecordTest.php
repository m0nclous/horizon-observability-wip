<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\DashboardTruth\Datasets\Datasets;
use DF\HorizonObservability\Tests\DashboardTruth\Record\Recorder;
use DF\HorizonObservability\Tests\DashboardTruth\Record\RecordingCase;
use DF\HorizonObservability\Tests\DashboardTruth\Record\WorldKeeper;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;

/*
| The record stage of the dashboard truth suite (T17, design sections 4 and 6.2): one test per dataset, run by
| tests/DashboardTruth/bin/run in a Pest process of its own with DASHBOARD_TRUTH_RECORD=1, DASHBOARD_TRUTH_RUN and
| DASHBOARD_TRUTH_RUN_DIR (and DASHBOARD_TRUTH_DATASETS to choose datasets). Without DASHBOARD_TRUTH_RECORD the tests
| are skipped and the clock shims are never defined.
|
| The shims load here, at file load: PHP caches how it resolves an unqualified call, so they must exist before
| Horizon's first microtime() call of the process (design X1).
*/

if (RecordingCase::recording()) {
    require_once __DIR__.'/Record/horizon-clock-shim.php';
}

uses(RecordingCase::class)->group('dashboard-truth-record', 'redis');

afterEach(function (): void {
    Scenario::stop();
});

it('records a dataset on its virtual clock and keeps its world', function (string $name): void {
    /** @var RecordingCase $this */
    $dataset = Datasets::get($name);
    $directory = WorldKeeper::directory(RecordingCase::runDir(), $name);
    $manifest = (new Recorder($this, $dataset, $directory))->record();

    expect($manifest->prefix)->toBe(WorldKeeper::prefix(RecordingCase::runId(), $name))
        ->and($manifest->end)->toBe($dataset->epoch() + $dataset->span())
        ->and($manifest->attempts)->not->toBeEmpty()
        ->and(is_file($directory.'/recording.ndjson'))->toBeTrue();
})->with(Datasets::selected())->skip(! RecordingCase::recording(), 'the record stage runs from tests/DashboardTruth/bin/run (DASHBOARD_TRUTH_RECORD=1)');
