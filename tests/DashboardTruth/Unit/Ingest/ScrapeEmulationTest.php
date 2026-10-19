<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Manifest;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Recording;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\SeriesStore;
use DF\HorizonObservability\Tests\DashboardTruth\Ingest\ScrapeEmulation;
use DF\HorizonObservability\Tests\Support\Exposition;

/*
| The emulated scrape loop of the ingest (T17, design section 6.3), rule by rule: target labels, empty labels
| dropped, `le`/`quantile` normalised to Prometheus 3 float text only for histograms and summaries, NaN and +-Inf
| kept, `up` per slot, staleness markers on a vanished series, at a failed slot and after a target's active interval.
*/

pest()->group('grafana');

/**
 * A store from scrapes [t ms, ok, exposition] of one target active [from, to].
 *
 * @param  list<array{0: int, 1: bool, 2: string|null}>  $scrapes
 */
function dashboardTruthEmulate(array $scrapes, int $to = 1740966000): SeriesStore
{
    $path = tempnam(sys_get_temp_dir(), 'ho-t17-recording');
    $recording = Recording::create($path);

    foreach ($scrapes as [$time, $ok, $exposition]) {
        $recording->append('app', $time, $ok, $exposition);
    }

    $recording->close();
    $manifest = new Manifest('unit', 1740960000, 1740966000, 10, 'ho_t17_unit_unit:', '/dev/null', 't', [
        ['name' => 'app', 'job' => 'app', 'instance' => 'app:8080', 'interval' => 10, 'phase' => 0.0, 'from' => 1740960000, 'to' => $to, 'failed' => []],
    ], [], [], [], [], ['off']);

    try {
        return ScrapeEmulation::store($manifest, Recording::open($path));
    } finally {
        unlink($path);
    }
}

it('normalises le and quantile only where Prometheus 3 does', function (): void {
    $exposition = Exposition::parse(implode("\n", [
        '# TYPE ho_x_seconds histogram',
        'ho_x_seconds_bucket{queue="default",le="1"} 2',
        'ho_x_seconds_bucket{queue="default",le="0.25"} 1',
        'ho_x_seconds_bucket{queue="default",le="1e6"} 3',
        'ho_x_seconds_bucket{queue="default",le="+Inf"} 3',
        'ho_x_seconds_count{queue="default"} 3',
        '# TYPE ho_y summary',
        'ho_y{quantile="0.5"} NaN',
        '# TYPE ho_z gauge',
        'ho_z{le="1",tts_url=""} -Inf',
        '',
    ]));
    $samples = array_map(static fn (array $sample): string => $sample[0].json_encode($sample[1]), ScrapeEmulation::samples($exposition));

    expect($samples)->toContain('ho_x_seconds_bucket{"le":"1.0","queue":"default"}')
        ->toContain('ho_x_seconds_bucket{"le":"0.25","queue":"default"}')
        ->toContain('ho_x_seconds_bucket{"le":"1e+06","queue":"default"}')
        ->toContain('ho_x_seconds_bucket{"le":"+Inf","queue":"default"}')
        ->toContain('ho_y{"quantile":"0.5"}')
        // A gauge keeps its le as served; an empty label value is dropped
        ->toContain('ho_z{"le":"1"}');
});

it('formats floats as Go does', function (string $text, string $expected): void {
    expect(ScrapeEmulation::float($text))->toBe($expected);
})->with([['0', '0.0'], ['1', '1.0'], ['10', '10.0'], ['120', '120.0'], ['0.25', '0.25'], ['100000', '100000.0'], ['1000000', '1e+06'], ['0.0001', '0.0001'], ['0.00001', '1e-05'], ['123456789', '1.23456789e+08'], ['+Inf', '+Inf'], ['-1', '-1.0']]);

it('rejects job and instance labels in an exposition', function (): void {
    ScrapeEmulation::samples(Exposition::parse("ho_a{job=\"x\"} 1\n"));
})->throws(InvalidArgumentException::class, 'honor_labels');

it('adds target labels and up, and writes staleness markers as a scrape loop does', function (): void {
    $store = dashboardTruthEmulate([
        [1740960000000, true, "ho_a 1\nho_b 2\n"],
        [1740960010000, true, "ho_a 2\n"],
        [1740960020000, false, null],
        [1740960030000, true, "ho_a 4\nho_b 5\n"],
    ], to: 1740960030);
    $series = $store->all();

    expect($series['__name__="ho_a",instance="app:8080",job="app"'])->toBe(['labels' => ['__name__' => 'ho_a', 'instance' => 'app:8080', 'job' => 'app'], 't' => [1740960000000, 1740960010000, 1740960020000, 1740960030000, 1740960040000], 'v' => [1.0, 2.0, null, 4.0, null]])
        ->and($series['__name__="ho_b",instance="app:8080",job="app"']['t'])->toBe([1740960000000, 1740960010000, 1740960030000, 1740960040000])
        ->and($series['__name__="ho_b",instance="app:8080",job="app"']['v'])->toBe([2.0, null, 5.0, null])
        ->and($series['__name__="up",instance="app:8080",job="app"']['v'])->toBe([1.0, 1.0, 0.0, 1.0]);
});
