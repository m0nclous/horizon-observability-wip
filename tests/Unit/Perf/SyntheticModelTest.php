<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricType;
use Workbench\App\Perf\SyntheticModel;

/*
| The perf-profile model (docker/perf/README.md): a faithful port of the Python probe model (synth.py) whose history
| the pilot dashboard was first measured on, so those reference measurements can be reproduced, plus the package's
| catalog schema for the measurements of the rewritten dashboard.
*/

/**
 * @return array<string, array{lines: int, sha256: string}>
 */
function perfProbeFixture(): array
{
    return require dirname(__DIR__, 2).'/Fixtures/perf/synth-medium.php';
}

/**
 * [family => [label names]] of an exposition without the prefix, `le` dropped.
 *
 * @return array<string, list<string>>
 */
function perfFamilies(SyntheticModel $model, int $t): array
{
    $families = [];

    foreach ($model->series($t) as [$name, $labels]) {
        preg_match_all('/(\w+)="/', $labels, $matches);
        $families[$name] = array_values(array_diff($matches[1], ['le']));
    }

    return $families;
}

it('reproduces the probe model byte for byte', function (string $time, array $expected): void {
    $text = (new SyntheticModel('medium', SyntheticModel::LEGACY))->exposition(str_contains($time, '.') ? (float) $time : (int) $time);

    expect(substr_count($text, "\n"))->toBe($expected['lines'])
        ->and(hash('sha256', $text))->toBe($expected['sha256']);
})->with(array_combine(
    array_map(strval(...), array_keys(perfProbeFixture())),
    array_map(static fn (int|string $time, array $expected): array => [(string) $time, $expected], array_keys(perfProbeFixture()), perfProbeFixture()),
));

it('matches the full probe exposition at one time', function (): void {
    $expected = gzdecode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/perf/synth-medium-1792201716.txt.gz'));

    expect((new SyntheticModel('medium', SyntheticModel::LEGACY))->exposition(1792201716))->toBe($expected);
});

it('computes erf exactly as the C library the probe used', function (float $x, float $erf): void {
    expect(SyntheticModel::erf($x))->toBe($erf);
})->with([
    [1e-09, 1.1283791670955127e-09],
    [0.1, 0.1124629160182849],
    [0.5, 0.5204998778130465],
    [0.84, 0.7651427114549946],
    [0.9, 0.7969082124228322],
    [1.2, 0.9103139782296353],
    [1.3, 0.9340079449406524],
    [-0.4, -0.42839235504666845],
    [2.0, 0.9953222650189527],
    [2.9, 0.9999589021219005],
    [3.9, 0.9999999652077514],
    [5.5, 0.9999999999999927],
    [6.5, 1.0],
    [-2.2, -0.9981371537020182],
]);

it('rounds and prints values like Python', function (): void {
    expect(array_map(SyntheticModel::round3(...), [2.0005, 1.0005, 0.0125, 2.675, 1234.5675, 0.0005]))
        ->toBe([2.001, 1.0, 0.013, 2.675, 1234.568, 0.001])
        ->and(array_map(SyntheticModel::format(...), [3, 12.0, 0.05, 2.5, 1234.568]))
        ->toBe(['3', '12.0', '0.05', '2.5', '1234.568']);
});

it('emits only catalog families with the catalog labels in the catalog schema', function (): void {
    $families = perfFamilies(new SyntheticModel('medium', SyntheticModel::CATALOG), 1792201716);
    $histograms = array_map(static fn (Metric $metric): string => $metric->value, array_filter(Metric::cases(), static fn (Metric $metric): bool => $metric->type() === MetricType::Histogram));

    foreach ($families as $family => $labels) {
        $base = preg_replace('/_(bucket|sum|count)$/', '', $family);
        $metric = Metric::tryFrom(in_array($base, $histograms, true) ? (string) $base : $family);

        expect($metric)->not->toBeNull("{$family} is not in the catalog")
            ->and($labels)->toBe($metric?->labels(), "labels of {$family}");
    }

    expect(array_keys($families))->toContain('queue_oldest_pending_job_age_seconds', 'exporter_collector_success', 'exporter_build_info')
        ->not->toContain('horizon_current_processes', 'queue_oldest_pending_job_age');
});

it('names the families as the pilot did in the legacy schema', function (): void {
    $families = perfFamilies(new SyntheticModel('medium', SyntheticModel::LEGACY), 1792201716);

    expect($families)->toHaveKey('horizon_current_processes')
        ->toHaveKey('queue_oldest_pending_job_age')
        ->and($families['horizon_info'])->toBe(['url', 'tts_url'])
        ->and($families['queue_batch_progress'])->toBe(['batch', 'name', 'status', 'size', 'created', 'id'])
        ->and(array_filter(array_keys($families), static fn (string $name): bool => str_starts_with($name, 'exporter_')))->toBe([]);
});

it('never exports job or instance labels', function (string $schema): void {
    expect((new SyntheticModel('medium', $schema))->exposition(1792201716))
        ->not->toContain('job="')
        ->not->toContain('instance="');
})->with([SyntheticModel::LEGACY, SyntheticModel::CATALOG]);

it('uses the namespace of the schema unless one is given', function (): void {
    expect((new SyntheticModel('medium', SyntheticModel::LEGACY))->prefix())->toBe('office_')
        ->and((new SyntheticModel)->prefix())->toBe('ho_')
        ->and((new SyntheticModel('medium', SyntheticModel::CATALOG, 'shop'))->prefix())->toBe('shop_')
        ->and((new SyntheticModel('medium', SyntheticModel::CATALOG, ''))->exposition(1792201716))->toStartWith('horizon_status{} ');
});

it('keeps counters monotonic across scrapes', function (): void {
    $model = new SyntheticModel;
    $values = static function (int $t) use ($model): array {
        $values = [];

        foreach ($model->series($t) as [$name, $labels, $value]) {
            if ($name === 'queue_jobs_total' || str_ends_with($name, '_count') || str_ends_with($name, '_bucket')) {
                $values[$name.'{'.$labels.'}'] = $value;
            }
        }

        return $values;
    };
    $before = $values(1792201716);
    $after = $values(1792201726);
    $decreasing = array_filter(array_keys($before), static fn (string $key): bool => ($after[$key] ?? -1) < $before[$key]);

    expect($before)->not->toBeEmpty()
        ->and($decreasing)->toBe([]);
});

it('sizes the profiles as docker/perf/README.md describes', function (): void {
    $medium = new SyntheticModel;
    $large = new SyntheticModel('large');
    $supervisors = static fn (SyntheticModel $model): int => count(array_filter(iterator_to_array($model->series(1792201716), false), static fn (array $row): bool => $row[0] === 'horizon_supervisor_max_processes'));
    $queues = static fn (SyntheticModel $model): int => count(array_filter(iterator_to_array($model->series(1792201716), false), static fn (array $row): bool => $row[0] === 'queue_pending_jobs'));
    $classes = static fn (SyntheticModel $model): int => count(array_filter(iterator_to_array($model->series(1792201716), false), static fn (array $row): bool => $row[0] === 'queue_job_classes'));

    expect($medium->count(1792201716))->toBeGreaterThan(5000)->toBeLessThan(5600)
        ->and([$supervisors($medium), $queues($medium), $classes($medium)])->toBe([4, 12, 160])
        ->and([$supervisors($large), $queues($large), $classes($large)])->toBe([10, 40, 500]);
});

it('writes OpenMetrics samples with the scrape labels and keeps horizon_info apart', function (): void {
    $model = new SyntheticModel;
    $samples = $model->samples(1792201710, 'job="perf-horizon",instance="perf-exporter"');

    expect($samples)->toStartWith('ho_horizon_status{job="perf-horizon",instance="perf-exporter"} 1 1792201710'."\n")
        ->toContain(',job="perf-horizon",instance="perf-exporter"} ')
        ->not->toContain('horizon_info')
        ->and($model->horizonInfo(1792201710, 'job="j",instance="i"'))
        ->toBe('ho_horizon_info{url="https://office.example.test/horizon",api_path="/horizon/observability",tts_url="http://piper:5000",job="j",instance="i"} 1 1792201710'."\n");
});

it('rejects unknown profiles and schemas', function (): void {
    expect(static fn (): SyntheticModel => new SyntheticModel('huge'))->toThrow(InvalidArgumentException::class, 'Unknown profile "huge"')
        ->and(static fn (): SyntheticModel => new SyntheticModel('medium', 'other'))->toThrow(InvalidArgumentException::class, 'Unknown schema "other"');
});
