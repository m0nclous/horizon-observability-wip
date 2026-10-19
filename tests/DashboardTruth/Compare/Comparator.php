<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Compare;

use Closure;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\CaseContext;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\CaseDef;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Category;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\DomSpec;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Durations;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Expectation;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Grid;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Level;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Manifest;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Observation as Captured;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\TargetSpec;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\SeriesStore;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Specs;
use RuntimeException;
use Throwable;

/**
 * The assert stage of one case on one Grafana version (design sections 3 and 8): for every target spec of the case's
 * render, the expectation on Grafana's captured grid and on both replays (S and the next coarser step) against the
 * frames, exactly: the same label sets, every point (floats of the same arithmetic to 1e-9 relative), and for a tile
 * the sum of the points against C(from, to); then the DOM of every DOM spec; then the {@see Checks}.
 *
 * It reads only artifacts plus the browserless replays against the still-running Grafana; it never queries
 * Prometheus, so every evaluated value went through Grafana.
 */
final readonly class Comparator
{
    private const RELATIVE = 1e-9;

    private const DIFFERENCES = 20;

    public function __construct(
        private string $runDir,
        private ?Replays $replays,
    ) {}

    /**
     * @param  array<string, mixed>  $planned
     * @return list<Assertion>
     */
    public function run(CaseDef $case, array $planned, Captured $observation, Manifest $manifest, SeriesStore $store): array
    {
        $assertions = Checks::run($case, $planned, $observation);

        // A negative control asserts its checks only; an incomplete page has nothing to compare
        if ($case->expect instanceof Category || ! $observation->complete()) {
            return $assertions;
        }

        $context = null;

        foreach (Specs::forMode($case->mode) as $target) {
            $request = $observation->request((string) $target->panel, $target->refId);

            if ($request === null || ! is_array($request['body'])) {
                $assertions[] = $this->failed($case, $observation, $target, 'grid', Category::Infrastructure, 'an answered request', 'the target was not queried');

                continue;
            }

            $context ??= new CaseContext(
                $case,
                $observation->grafana(),
                $manifest,
                $store,
                (int) $request['body']['from'],
                (int) $request['body']['to'],
                $observation->variables(),
            );
            $response = is_array($request['response']) ? $request['response'] : null;
            $frames = Observation::frames($response, $target->refId);
            $assertions[] = $this->compare($context, $target, $frames, Grid::CAPTURED);

            if (! $target->range || ! $this->replays instanceof Replays) {
                continue;
            }

            $captured = Observation::step($frames);

            if ($captured === null) {
                continue;
            }

            foreach (Replays::steps($context->scrapeInterval() * 1000, $captured) as $step) {
                $source = 'replay '.Durations::format($step);

                try {
                    $replayed = $this->replays->post((string) $request['url'], $request['body'], $step);
                } catch (Throwable $e) {
                    $assertions[] = $this->failed($case, $observation, $target, $source, Category::Infrastructure, 'a replay answer', $e->getMessage());

                    continue;
                }

                $replayFrames = Observation::frames($replayed, $target->refId);
                $got = Observation::step($replayFrames);

                if ($got !== $step) {
                    $assertions[] = $this->failed($case, $observation, $target, $source, Category::Infrastructure, 'step '.Durations::format($step), 'step '.($got === null ? 'none' : Durations::format($got)).': '.(Observation::error($replayed, $target->refId) ?? 'no frame'));

                    continue;
                }

                $assertions[] = $this->compare($context, $target, $replayFrames, $source);
            }
        }

        foreach (Specs::panels() as $panel) {
            $dom = $panel->dom();

            if ($dom !== null && in_array($case->mode, $dom->modes, true) && $context instanceof CaseContext) {
                $assertions[] = $this->compareDom($context, $dom, $observation);
            }
        }

        return $assertions;
    }

    /**
     * @param  list<array<array-key, mixed>>  $frames
     */
    private function compare(CaseContext $context, TargetSpec $target, array $frames, string $source): Assertion
    {
        $executed = Observation::executed($frames);
        $stepMs = Observation::step($frames);
        $replay = $this->replayCommand($context, $target, $source === Grid::CAPTURED ? $stepMs : Durations::parse(substr($source, strlen('replay '))));
        $base = [
            'case' => $context->case->id,
            'grafana' => $context->grafana,
            'panel' => $target->panel,
            'refId' => $target->refId,
            'level' => Level::Frame->value,
            'source' => $source,
            'executed' => $executed,
            'step' => $stepMs === null ? null : Durations::format($stepMs),
            'replay' => $replay,
        ];

        try {
            $grid = $target->range
                ? new Grid(Observation::grid($frames, $stepMs ?? throw new RuntimeException('infrastructure: the frame has no step'), $context->fromMs, $context->toMs), $stepMs, $source)
                : new Grid([$context->toMs], $stepMs ?? 0, $source);
            $expected = ($target->expect)($context, $grid);
        } catch (Throwable $e) {
            $category = str_starts_with($e->getMessage(), 'exporter mismatch') ? Category::ExporterMismatch : Category::Infrastructure;

            return new Assertion(...$base, passed: false, category: $category, expected: 'an expectation', actual: $e::class.': '.$e->getMessage());
        }

        $actual = Observation::series($frames);
        $differences = self::differences($expected, $actual);

        return new Assertion(
            ...$base,
            passed: $differences === [],
            category: $differences === [] ? null : Category::DashboardDefect,
            expected: $expected->summary,
            actual: $this->summary($actual),
            differences: array_slice($differences, 0, self::DIFFERENCES),
            nonTrivial: $expected->nonTrivial(),
        );
    }

    private function compareDom(CaseContext $context, DomSpec $dom, Captured $observation): Assertion
    {
        $read = $observation->dom('panel-'.$dom->panel);
        $actual = $read === null ? [] : $read['lines'];

        try {
            $expected = ($dom->expect)($context);
        } catch (Throwable $e) {
            return new Assertion($context->case->id, $context->grafana, $dom->panel, null, Level::Dom->value, 'dom', false, Category::Infrastructure, 'an expectation', $e->getMessage());
        }

        $differences = [];

        if ($actual !== $expected) {
            $differences[] = sprintf('the panel reads "%s", the truth is "%s"', implode(' | ', $actual), implode(' | ', $expected));
        }

        // The tile number is the formatted sum of the captured points of its range target
        if ($dom->sumOf !== null && $dom->format instanceof Closure) {
            $request = $observation->request((string) $dom->panel, $dom->sumOf);
            $frames = Observation::frames(is_array($request['response'] ?? null) ? $request['response'] : null, $dom->sumOf);
            $sum = 0.0;

            foreach (Observation::series($frames) as $series) {
                $sum += array_sum(array_map(static fn (?float $value): float => $value ?? 0.0, $series['points']));
            }

            $number = $actual === [] ? null : $actual[count($actual) - 1];

            if ($number !== ($dom->format)($sum)) {
                $differences[] = sprintf('the number %s is not the sum %s of the %s points', var_export($number, true), ($dom->format)($sum), $dom->sumOf);
            }
        }

        return new Assertion(
            $context->case->id,
            $context->grafana,
            $dom->panel,
            null,
            Level::Dom->value,
            'dom',
            $differences === [],
            $differences === [] ? null : Category::DashboardDefect,
            implode(' | ', $expected),
            implode(' | ', $actual),
            $differences,
            nonTrivial: true,
        );
    }

    /**
     * Every difference between the expected and the actual series: label sets, then every point of the grid the
     * expectation was evaluated on (a point the dashboard left out reads `no point`). A series without points is no
     * series, on either side (Prometheus has no empty series; Grafana may send a frame without rows).
     *
     * @param  list<array{labels: array<string, string>, points: array<int, float|null>}>  $actual
     * @return list<string>
     */
    public static function differences(Expectation $expected, array $actual): array
    {
        $differences = [];
        $byKey = [];

        foreach ($actual as $series) {
            if ($series['points'] === []) {
                continue;
            }

            $key = SeriesStore::key($series['labels']);

            if (isset($byKey[$key])) {
                $differences[] = sprintf('the series {%s} appears twice', $key);
            }

            $byKey[$key] = $series['points'];
        }

        $wanted = [];

        foreach ($expected->series as $series) {
            if ($series['points'] === []) {
                continue;
            }

            $key = SeriesStore::key($series['labels']);
            $wanted[$key] = true;

            if (! isset($byKey[$key])) {
                $differences[] = sprintf('the series {%s} is missing (expected %d points)', $key, count($series['points']));

                continue;
            }

            $points = $byKey[$key];

            foreach ($series['points'] + $points as $time => $ignored) {
                $want = $series['points'][$time] ?? null;
                $have = $points[$time] ?? null;

                if (! self::same($want, $have)) {
                    $differences[] = sprintf(
                        '{%s} at %s: expected %s, actual %s',
                        $key,
                        gmdate('Y-m-d\TH:i:s\Z', intdiv((int) $time, 1000)),
                        $want === null ? 'no point' : self::number($want),
                        $have === null ? 'no point' : self::number($have),
                    );
                }
            }
        }

        foreach (array_keys($byKey) as $key) {
            if (! isset($wanted[$key])) {
                $differences[] = sprintf('the unexpected series {%s}', $key);
            }
        }

        if ($expected->total !== null) {
            $sum = 0.0;

            foreach ($actual as $series) {
                $sum += array_sum(array_map(static fn (?float $value): float => $value ?? 0.0, $series['points']));
            }

            if (! self::same($expected->total, $sum)) {
                $differences[] = sprintf('the points add up to %s, the period count C(from, to) is %s', self::number($sum), self::number($expected->total));
            }
        }

        return $differences;
    }

    private static function same(?float $expected, ?float $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        if (is_nan($expected) || is_nan($actual)) {
            return is_nan($expected) && is_nan($actual);
        }

        if (is_infinite($expected) || is_infinite($actual)) {
            return $expected === $actual;
        }

        return abs($expected - $actual) <= self::RELATIVE * max(1.0, abs($expected));
    }

    private static function number(float $value): string
    {
        return is_finite($value) && floor($value) === $value ? (string) (int) $value : (string) $value;
    }

    /**
     * @param  list<array{labels: array<string, string>, points: array<int, float|null>}>  $series
     */
    private function summary(array $series): string
    {
        $parts = [];

        foreach ($series as $one) {
            $values = array_values(array_filter($one['points'], static fn (?float $value): bool => $value !== null));
            $parts[] = sprintf('{%s} %d points, sum %s%s', SeriesStore::key($one['labels']), count($one['points']), self::number(array_sum($values)), count($values) === 1 ? ', value '.self::number($values[0]) : '');
        }

        return $parts === [] ? 'no series' : implode('; ', $parts);
    }

    private function failed(CaseDef $case, Captured $observation, TargetSpec $target, string $source, Category $category, string $expected, string $actual): Assertion
    {
        return new Assertion($case->id, $observation->grafana(), $target->panel, $target->refId, Level::Frame->value, $source, false, $category, $expected, $actual);
    }

    private function replayCommand(CaseContext $context, TargetSpec $target, ?int $stepMs): string
    {
        return sprintf(
            'php tests/DashboardTruth/bin/run replay --run-dir=%s --case=%s --grafana=%s --panel=%d --ref=%s%s',
            $this->runDir,
            $context->case->id,
            $context->grafana,
            $target->panel,
            $target->refId,
            $stepMs === null || ! $target->range ? '' : ' --step='.Durations::format($stepMs),
        );
    }
}
