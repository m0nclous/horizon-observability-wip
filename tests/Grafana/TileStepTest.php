<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Owner decision 13, T13 QA round 3 finding 2: the Completed (#8 A) and Failed (#4 A) tiles add up to the table count
 * (#24 C, Attempts::delta()) and to the truth at the graph steps Grafana really sends, also when a series appears
 * between two step boundaries. At 1920 px and 10-second scrapes the Completed tile gets 10 s steps for a 1-hour range
 * and 1 minute for 6 hours, the Failed tile 15 s and 2 minutes; the graph #14 (full width) 10 s and 15 s. The round 2
 * form counted a series from the first subquery point after its first sample, so it lost the attempts of its first
 * partial step whenever the step was longer than the scrape interval (and T16's 90-second hold at 0 does not always
 * contain a 2-minute boundary).
 *
 * The scenario, at 10-second scrapes: an old class; classes that appear with a history (1000) at every 10-second phase
 * of a 2-minute step, near the period start, in the middle and near the period end; classes created inside the range
 * and held at 0 for 90 s by the exporter (T16) at every phase; one created before the start whose hold ends inside the
 * range; one still held at the end; and a class with a 4-minute scrape gap (shorter than the tiles' 10-minute lookback
 * and than Prometheus' 5 minutes, which the step edges of #14 use: no attempt is lost). The truth is computed from the
 * samples: per series the last value at the end minus the last value at the start, or minus its first value when it
 * appears inside the range (owner decision 13, T16: a first value is never counted). The period ends now (no samples
 * after it) or in the past (samples go on: a series that appears after the end is not counted).
 *
 * Grafana's range query (points from the start rounded down to the step up to the end, reduced with sum) evaluates
 * each point as an instant query at its time; promtool does the same, one test per point (a subquery over the range
 * would hold more samples than promtool's engine allows). Every point must equal the attempts of its step
 * (tileStepPoint(), Attempts::perStepTotal()), and the points must add up to the truth. Where the browser's UTC offset
 * shifts Grafana's points off the subquery points (multiples of the step since 1970), the points are at the offset.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

const TILE_STEP_SCRAPE = 10;

const TILE_STEP_LOOKBACK = 600;

const TILE_STEP_HOLD = 9;

/**
 * The series of the scenario as sample lists (index i is the sample at i * 10 s, null for none), by class.
 *
 * @return array<string, list<int|null>>
 */
function tileStepSeries(int $from, int $to): array
{
    $last = intdiv($to + TILE_STEP_LOOKBACK, TILE_STEP_SCRAPE);
    $samples = static function (callable $value) use ($last): array {
        $list = [];

        for ($i = 0; $i <= $last; $i++) {
            $list[] = $value($i);
        }

        return $list;
    };
    // A class that appears at sample $k with its history: 1000, then one attempt a scrape
    $born = static fn (int $k): array => $samples(static fn (int $i): ?int => $i < $k ? null : 1000 + $i - $k);
    // A class created just before sample $k, exported as 0 for 90 s, then with every attempt since its creation
    $held = static fn (int $k): array => $samples(static fn (int $i): ?int => $i < $k ? null : ($i < $k + TILE_STEP_HOLD ? 0 : $i - $k + 1));
    $middle = intdiv(intdiv($from + $to, 2), 120) * 120 / TILE_STEP_SCRAPE;

    $series = ['Old' => $samples(static fn (int $i): int => 5000 + $i)];

    foreach (range(0, 14) as $j) {
        $series['NearStart'.$j] = $born(intdiv($from - 85, TILE_STEP_SCRAPE) + $j);
        $series['NearEnd'.$j] = $born(intdiv($to - 125, TILE_STEP_SCRAPE) + $j);
    }

    foreach (range(0, 12) as $j) {
        $series['Middle'.$j] = $born($middle + $j);
        $series['Held'.$j] = $held($middle + 30 + $j);
    }

    $series['HeldOverStart'] = $held(intdiv($from - 40, TILE_STEP_SCRAPE));
    $series['HeldAtEnd'] = $held(intdiv($to - 50, TILE_STEP_SCRAPE));
    // 24 samples (4 minutes) without a scrape in the middle of the range: shorter than the tiles' lookback (10 minutes)
    // and than Prometheus' (5 minutes), which the step edges of #14 use
    $series['Gap'] = $samples(static fn (int $i): ?int => $i >= $middle - 60 && $i < $middle - 36 ? null : 200 + $i);

    return $series;
}

/**
 * The last sample at or before $time within the lookback, or null.
 *
 * @param  list<int|null>  $samples
 */
function tileStepValueAt(array $samples, int $time): ?int
{
    for ($i = intdiv($time, TILE_STEP_SCRAPE); $i >= 0 && $i * TILE_STEP_SCRAPE > $time - TILE_STEP_LOOKBACK; $i--) {
        if (isset($samples[$i])) {
            return $samples[$i];
        }
    }

    return null;
}

/**
 * The smallest sample in ($after, $until], or null.
 *
 * @param  list<int|null>  $samples
 */
function tileStepFirstIn(array $samples, int $after, int $until): ?int
{
    $first = null;

    foreach ($samples as $i => $value) {
        $time = $i * TILE_STEP_SCRAPE;

        if ($value !== null && $time > $after && $time <= $until) {
            $first = $first === null ? $value : min($first, $value);
        }
    }

    return $first;
}

/**
 * The attempts of a series in ($after, $until] (T16 rule): its last value at the end minus its last value at the start,
 * or minus its first value when it appears inside.
 *
 * @param  list<int|null>  $samples
 */
function tileStepAttempts(array $samples, int $after, int $until): int
{
    $end = tileStepValueAt($samples, $until);
    $start = tileStepValueAt($samples, $after) ?? tileStepFirstIn($samples, $after, $until);

    return $end === null || $start === null ? 0 : max(0, $end - $start);
}

/**
 * The input series for promtool, run-length encoded (`_xN`, `a+1xN`, `axN`).
 *
 * @param  array<string, list<int|null>>  $series
 * @return list<array{series: string, values: string}>
 */
function tileStepInput(array $series, string $status, int $last): array
{
    $encode = static function (array $samples): string {
        $tokens = [];
        $count = count($samples);

        for ($i = 0; $i < $count; $i = $j) {
            $j = $i + 1;

            if ($samples[$i] === null) {
                while ($j < $count && $samples[$j] === null) {
                    $j++;
                }

                $tokens[] = '_x'.($j - $i);

                continue;
            }

            $delta = $j < $count && $samples[$j] !== null ? $samples[$j] - $samples[$i] : 0;
            $delta = in_array($delta, [0, 1], true) ? $delta : 0;

            while ($j < $count && $samples[$j] !== null && $delta === $samples[$j] - $samples[$j - 1]) {
                $j++;
            }

            $tokens[] = $samples[$i].'+'.$delta.'x'.($j - $i - 1);
        }

        return implode(' ', $tokens);
    };
    $input = [
        ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x'.$last],
        ['series' => 'ho_queue_pending_jobs{job="app", instance="app:8080", connection="redis", queue="default"}', 'values' => '0x'.$last],
    ];

    foreach ($series as $class => $samples) {
        $input[] = [
            'series' => sprintf('ho_queue_jobs_total{job="app", instance="app:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\%s", status="%s"}', $class, $status),
            'values' => $encode($samples),
        ];
    }

    return $input;
}

/**
 * Panel $id $refId as Grafana sends it for the period ($to - $range, $to] at the step $step (seconds).
 */
function tileStepExpr(int $id, string $refId, int $to, int $range, int $step): string
{
    return Promtool::interpolate(strtr(Rendered::expressions()['panel '.$id.' '.$refId], [
        '${__interval_ms}' => (string) ($step * 1000),
        '$__interval_ms' => (string) ($step * 1000),
        '$__interval' => $step.'s',
    ]), '.+', $to, $range);
}

/**
 * The value of the tile's point $point (Attempts::perStepTotal()): every series' attempts in its step (PREV, PREV +
 * step] around the point, PREV the multiple of the step at or before it, from the period start for the step that holds
 * it, to the period end for the last point (its step reaches the end), 0 for a step that ends before the period. A
 * series starts at its value at PREV, else at its first value inside the step (the smallest sample from one step
 * before the point to one step after it).
 *
 * @param  array<string, list<int|null>>  $series
 */
function tileStepPoint(array $series, int $point, int $step, int $from, int $to): int
{
    $previous = intdiv($point, $step) * $step;
    $end = $point + $step > $to ? $to : $previous + $step;

    if ($previous + $step <= $from) {
        return 0;
    }

    return array_sum(array_map(static function (array $samples) use ($end, $point, $step, $previous, $from): int {
        $last = tileStepValueAt($samples, $end);
        $first = ($previous <= $from ? tileStepValueAt($samples, $from) : null)
            ?? tileStepValueAt($samples, $previous)
            ?? tileStepFirstIn($samples, $point - $step, $point + $step);

        return $last === null || $first === null ? 0 : max(0, $last - $first);
    }, $series));
}

/**
 * Grafana's range query of the panels at $step with the points at $offset plus multiples of the step (the browser's
 * UTC offset), from the period start rounded down to the end, as promtool tests: each point is an instant query at its
 * time, as the range query evaluates it, and must equal the model ({@see tileStepPoint()}); the table (#24 C, an
 * instant query at the end) must equal the truth. Returns the test group and the model's points.
 *
 * @return array{0: array<string, mixed>, 1: list<int>}
 */
function tileStepGroup(int $from, int $to, int $step, int $offset, string $status, bool $past): array
{
    $range = $to - $from;
    $series = tileStepSeries($from, $to);
    $truth = array_sum(array_map(static fn (array $samples): int => tileStepAttempts($samples, $from, $to), $series));
    $tile = tileStepExpr($status === 'completed' ? PanelRegistry::COMPLETED : PanelRegistry::FAILED, 'A', $to, $range, $step);
    $graph = tileStepExpr(PanelRegistry::COMPLETED_BY_JOB, 'B', $to, $range, $step);
    $one = static fn (float|int $value): array => [['labels' => '{}', 'value' => $value]];
    $tests = [
        ['expr' => 'sum(('.tileStepExpr(PanelRegistry::QUEUE_SOURCE, 'C', $to, $range, $step).') and on (status) label_replace(vector(1), "status", "'.$status.'", "", ""))', 'eval_time' => $to.'s', 'exp_samples' => $one($truth)],
        // promtool loads the samples up to the last evaluation time: the last point reads the period end, and a period
        // in the past has samples after it
        ['expr' => 'vector(1)', 'eval_time' => ($past ? $to + TILE_STEP_LOOKBACK : $to).'s', 'exp_samples' => $one(1)],
    ];
    $points = [];

    for ($point = intdiv($from - $offset, $step) * $step + $offset; $point <= $to; $point += $step) {
        $points[] = $value = tileStepPoint($series, $point, $step, $from, $to);
        $tests[] = ['expr' => $tile, 'eval_time' => $point.'s', 'exp_samples' => $one($value)];

        // The graph #14 (completed per minute) at the aligned steps: the same attempts per step
        if ($status === 'completed' && $offset === 0) {
            $tests[] = ['expr' => $graph, 'eval_time' => $point.'s', 'exp_samples' => $one(round($value * 60 / $step, 10))];
        }
    }

    $last = intdiv($past ? $to + TILE_STEP_LOOKBACK : $to, TILE_STEP_SCRAPE);

    return [[
        'interval' => TILE_STEP_SCRAPE.'s',
        'input_series' => tileStepInput(array_map(static fn (array $samples): array => array_slice($samples, 0, $last + 1), $series), $status, $last),
        'promql_expr_test' => $tests,
    ], $points];
}

it('adds up to the truth and to the tables at the steps Grafana sends', function (int $range, int $step, string $status, bool $past): void {
    $from = 1205;
    $to = $from + $range;
    $truth = array_sum(array_map(static fn (array $samples): int => tileStepAttempts($samples, $from, $to), tileStepSeries($from, $to)));
    [$group, $points] = tileStepGroup($from, $to, $step, 0, $status, $past);
    [$code, $output] = Promtool::testRules([], [$group]);

    // The tile number (its points summed) is the truth; not trivially: new and held series are a large part of it
    expect(array_sum($points))->toBe($truth)
        ->and(count($points))->toBe(intdiv($to, $step) - intdiv($from, $step) + 1)
        ->and($truth)->toBeGreaterThan($range / TILE_STEP_SCRAPE + 1000)
        ->and($code)->toBe(0, tileStepOutput($output));
})->with([
    'Completed, 1 hour, 10 s' => [3600, 10, 'completed', false],
    'Failed, 1 hour, 15 s' => [3600, 15, 'failed', false],
    'Completed, 6 hours, 1 minute' => [21600, 60, 'completed', false],
    'Failed, 6 hours, 2 minutes' => [21600, 120, 'failed', false],
    'Completed, 6 hours, 15 s (the graph #14)' => [21600, 15, 'completed', false],
    'Completed, 1 hour, 2 minutes, a period in the past' => [3600, 120, 'completed', true],
    'Failed, 6 hours, 1 minute, a period in the past' => [21600, 60, 'failed', true],
]);

it('adds up to the truth where the UTC offset shifts the points off the subquery points', function (int $step, int $offset, bool $past): void {
    // Grafana's points are at the offset plus multiples of the step, the tile's subquery points at multiples of the
    // step: point t counts the step around it, the first point(s) whose step ends before the period count nothing
    $from = 1205;
    $to = $from + 1800;
    [$group, $points] = tileStepGroup($from, $to, $step, $offset, 'completed', $past);
    [$code, $output] = Promtool::testRules([], [$group]);

    expect(array_sum($points))->toBe(array_sum(array_map(static fn (array $samples): int => tileStepAttempts($samples, $from, $to), tileStepSeries($from, $to))))
        ->and($code)->toBe(0, tileStepOutput($output));
})->with([
    '1 minute, 30 s' => [60, 30, false],
    '2 minutes, 90 s' => [120, 90, false],
    '2 minutes, 90 s, a period in the past' => [120, 90, true],
    '1 hour, 30 minutes' => [3600, 1800, false],
]);

/**
 * promtool's output without the long expressions.
 */
function tileStepOutput(string $output): string
{
    return (string) preg_replace('/expr: "(?:[^"\\\\]|\\\\.)*"/', 'expr: ...', $output);
}
