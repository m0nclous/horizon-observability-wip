<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Expect\Core;

use LogicException;

/**
 * The counter period count C(selection, from, to) and its per-step forms (design section 7.2), on the stored samples
 * of the selected counter series. The honest per-sample-pair rule, per series:
 *
 * - the baseline is the last sample at or before `from` within LOOKBACK = max(10 m, 10 x S); without one, the first
 *   sample in (from, to], which is never counted (it is history, or the exporter's held 0);
 * - then every consecutive pair (a, b) up to the last sample <= `to` counts `b - a` if b >= a, else `b` (a reset
 *   counts its post-reset value, for that pair only);
 * - pairs across a scrape gap count their increment (the exporter's counters are exact); a gap longer than LOOKBACK
 *   right before `from` loses its attempts by the baseline rule itself (ADDENDUM R3.1/R2.4 O1, deviation D1).
 *
 * So a new series held at 0 counts all its attempts, and a series first seen with history counts only its increase.
 * Each counted increment belongs to the time of its later sample, so the per-step forms add up to C exactly.
 *
 * Series are deduplicated first (`max by (job, <family labels>)` over instances, Arch Q1.5); WP1 has one instance per
 * job and fails loudly on replicas, which WP5 adds with promtool-proven semantics.
 */
final readonly class Counters
{
    /**
     * @var array<string, array{labels: array<string, string>, t: list<int>, v: list<float|null>}>
     */
    private array $series;

    /**
     * @param  array<string, array{labels: array<string, string>, t: list<int>, v: list<float|null>}>  $series  the
     *                                                                                                          selected series
     */
    public function __construct(array $series, private int $lookbackMs)
    {
        $this->series = $this->dedupe($series);
    }

    /**
     * LOOKBACK = max(10 m, 10 x S) in milliseconds (ADDENDUM R3.3), S in seconds.
     */
    public static function lookbackMs(int $scrapeInterval): int
    {
        return max(600, 10 * $scrapeInterval) * 1000;
    }

    /**
     * The counted increments of the period (from, to]: series key => list of [time of the later sample, increment].
     *
     * @return array<string, list<array{0: int, 1: float}>>
     */
    public function pairs(int $fromMs, int $toMs): array
    {
        $pairs = [];

        foreach ($this->series as $key => $series) {
            $samples = [];

            foreach ($series['t'] as $index => $time) {
                if ($series['v'][$index] !== null && $time <= $toMs) {
                    $samples[] = [$time, $series['v'][$index]];
                }
            }

            $baseline = null;

            foreach ($samples as $index => [$time]) {
                if ($time <= $fromMs && $time > $fromMs - $this->lookbackMs) {
                    $baseline = $index;
                } elseif ($time > $fromMs) {
                    break;
                }
            }

            if ($baseline === null) {
                foreach ($samples as $index => [$time]) {
                    if ($time > $fromMs) {
                        $baseline = $index;

                        break;
                    }
                }
            }

            if ($baseline === null) {
                continue;
            }

            $counted = [];
            $counter = count($samples);

            for ($index = $baseline + 1; $index < $counter; $index++) {
                [$time, $value] = $samples[$index];
                $previous = $samples[$index - 1][1];
                $counted[] = [$time, $value >= $previous ? $value - $previous : $value];
            }

            $pairs[$key] = $counted;
        }

        return $pairs;
    }

    /**
     * C(from, to): the attempts of the period, summed over the selected series.
     */
    public function count(int $fromMs, int $toMs): float
    {
        return $this->sum($this->pairs($fromMs, $toMs), $fromMs, $toMs);
    }

    /**
     * Whether any selected series has a sample in the period or within LOOKBACK before its end.
     */
    public function present(int $atMs): bool
    {
        foreach ($this->series as $series) {
            if (Visibility::lastWithin($series, $atMs, $this->lookbackMs) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tile per-step points on a captured grid (`Attempts::perStepTotal()` docblock, the Completed and Failed
     * tiles): point t counts (P, P + s] with P = floor(t / s) x s; the step that holds `from` starts at `from`; the
     * last point, whose step reaches `to` (t + s > to), ends at `to`, counting (P, to]; points whose step ends at or
     * before `from` count nothing (null). The points add up to C(from, to) whenever the grid covers the period.
     *
     * @param  list<int>  $grid  point times, milliseconds
     * @return array<int, float|null> point time => attempts
     */
    public function tileSteps(array $grid, int $stepMs, int $fromMs, int $toMs): array
    {
        $pairs = $this->pairs($fromMs, $toMs);
        $points = [];

        foreach ($grid as $time) {
            $start = intdiv($time, $stepMs) * $stepMs;

            if ($start + $stepMs <= $fromMs) {
                $points[$time] = null;

                continue;
            }

            $end = $time + $stepMs > $toMs ? $toMs : $start + $stepMs;
            $points[$time] = $this->sum($pairs, max($start, $fromMs), $end);
        }

        return $points;
    }

    /**
     * @param  array<string, list<array{0: int, 1: float}>>  $pairs
     */
    private function sum(array $pairs, int $afterMs, int $untilMs): float
    {
        $sum = 0.0;

        foreach ($pairs as $counted) {
            foreach ($counted as [$time, $increment]) {
                if ($time > $afterMs && $time <= $untilMs) {
                    $sum += $increment;
                }
            }
        }

        return $sum;
    }

    /**
     * One series per (job, family labels): the instance is the only label a replica dedupe removes.
     *
     * @param  array<string, array{labels: array<string, string>, t: list<int>, v: list<float|null>}>  $series
     * @return array<string, array{labels: array<string, string>, t: list<int>, v: list<float|null>}>
     */
    private function dedupe(array $series): array
    {
        $groups = [];

        foreach ($series as $key => $one) {
            $labels = $one['labels'];
            unset($labels['instance']);
            $group = SeriesStore::key($labels);

            if (isset($groups[$group])) {
                throw new LogicException(sprintf('Replicas of %s (%s and %s): the replica dedupe of the counters is not modelled yet (WP5).', $group, $groups[$group][0], $key));
            }

            $groups[$group] = [$key, $one];
        }

        return array_map(static fn (array $entry): array => $entry[1], $groups);
    }
}
