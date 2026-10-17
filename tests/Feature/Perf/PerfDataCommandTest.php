<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Workbench\App\Perf\SyntheticModel;

/*
| workbench:perf-data (ADDENDUM R2.2 step 1, T11): OpenMetrics chunks aligned to the 2-hour block windows, with the
| labels the perf Prometheus adds on scrape.
*/

beforeEach(function (): void {
    $this->perfOut = sys_get_temp_dir().'/ho-perf-data-'.getmypid();
    File::deleteDirectory($this->perfOut);
});

afterEach(function (): void {
    File::deleteDirectory($this->perfOut);
});

it('lists the 2-hour windows of the history and the info chunk', function (): void {
    Artisan::call('workbench:perf-data', ['--hours' => 3, '--end' => 1792203000, '--list' => true]);

    // 1792192200 is the start (end - 3 h, a multiple of the step); its window starts at 1792188000.
    expect(preg_split('/\R/', trim(Artisan::output())))->toBe(['1792188000', '1792195200', '1792202400', 'info']);
});

it('writes every sample of a window once with the scrape labels', function (): void {
    $this->artisan('workbench:perf-data', ['--hours' => 0.25, '--step' => 15, '--end' => 1792203000, '--out' => $this->perfOut, '--job' => 'j', '--instance' => 'i'])
        ->assertSuccessful();

    $chunk = (string) file_get_contents($this->perfOut.'/chunk-1792202400.om');
    $lines = explode("\n", rtrim($chunk, "\n"));
    $times = array_unique(array_map(static fn (string $line): string => (string) strrchr($line, ' '), array_slice($lines, 0, -1)));
    $model = new SyntheticModel;

    // 15 minutes from 1792202100 span two windows: 1792195200 (the first 5 minutes) and 1792202400.
    expect(array_values(array_diff(scandir($this->perfOut) ?: [], ['.', '..'])))->toBe(['chunk-1792195200.om', 'chunk-1792202400.om', 'info.om'])
        ->and(substr_count((string) file_get_contents($this->perfOut.'/chunk-1792195200.om'), ' 1792202385'."\n"))->toBeGreaterThan(5000)
        ->and(end($lines))->toBe('# EOF')
        ->and(array_values($times))->toBe(array_map(static fn (int $t): string => ' '.$t, range(1792202400, 1792202985, 15)))
        ->and($lines[0])->toBe('ho_horizon_status{job="j",instance="i"} 1 1792202400')
        ->and(count($lines) - 1)->toBe(array_sum(array_map(static fn (int $t): int => $model->count($t) - 1, range(1792202400, 1792202985, 15))));
});

it('writes one window or the info chunk alone', function (): void {
    $this->artisan('workbench:perf-data', ['--hours' => 3, '--end' => 1792203000, '--out' => $this->perfOut, '--window' => 'info', '--schema' => 'legacy'])
        ->assertSuccessful();

    $info = file($this->perfOut.'/info.om', FILE_IGNORE_NEW_LINES) ?: [];

    expect(scandir($this->perfOut))->toBe(['.', '..', 'info.om'])
        ->and($info)->toHaveCount(7 * 1440 + 1)
        ->and($info[0])->toBe('office_horizon_info{url="https://office.example.test/horizon",tts_url="http://piper:5000",job="perf-horizon",instance="perf-exporter"} 1 1791598200')
        ->and(end($info))->toBe('# EOF');
});

it('refuses unknown windows, profiles and schemas', function (array $options, string $message): void {
    $this->artisan('workbench:perf-data', [...$options, '--end' => 1792203000, '--out' => $this->perfOut])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(is_dir($this->perfOut) ? scandir($this->perfOut) : [])->not->toContain('info.om');
})->with([
    'window' => [['--window' => '123'], 'Unknown window 123'],
    'profile' => [['--profile' => 'huge'], 'Unknown profile "huge"'],
    'schema' => [['--schema' => 'x'], 'Unknown schema "x"'],
]);
