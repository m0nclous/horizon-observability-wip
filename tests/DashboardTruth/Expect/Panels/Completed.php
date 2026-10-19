<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Expect\Panels;

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\CaseContext;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\DomSpec;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Expectation;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Grid;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Level;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\PanelSpec;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\TargetSpec;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\AttemptLog;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\Counters;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\Format;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\Visibility;
use LogicException;
use RuntimeException;

/**
 * #8 Completed (design section 7.3), restated from the `Tiles::completed()` and `Attempts::perStepTotal()`
 * docblocks and ADDENDUM R3.3, never from the panel's PromQL:
 *
 * - A (every render): the completed attempts per step, with the tile per-step semantics on the captured grid (Counters::tileSteps);
 *   the number is the sum of the points, the completed attempts of the period C(from, to); 0 while the application
 *   exports metrics without attempts;
 * - B (off): the caption `RPM: n`, n = round(completed in (to - W, to] per minute), W = max(5 m, 5 x S), "per
 *   minute over the last minutes like Horizon's Jobs Per Minute"; `RPM: —` without metrics;
 * - the DOM reads `Completed | RPM: n | N`, N the sum in the `locale` unit with 0 decimals.
 *
 * WP1 models the All selection; filters (queue, job class, batch scope O2), poll/stream C and replicas follow in WP6.
 */
final class Completed implements PanelSpec
{
    public const PANEL = 8;

    public function panel(): int
    {
        return self::PANEL;
    }

    public function targets(): array
    {
        return [
            new TargetSpec(
                panel: self::PANEL,
                refId: 'A',
                meaning: 'completed attempts per step (tile semantics); the number is their sum C(from, to)',
                // The same target JSON in all three renders (the poll and stream tiles are Mixed panels)
                modes: [Mode::Off, Mode::Poll, Mode::Stream],
                levels: [Level::Frame],
                range: true,
                expect: fn (CaseContext $context, Grid $grid): Expectation => $this->perStep($context, $grid),
                consumed: [],
            ),
            new TargetSpec(
                panel: self::PANEL,
                refId: 'B',
                meaning: 'RPM: completed per minute over the last 5 min or 5 x S if longer, rounded (the caption)',
                modes: [Mode::Off],
                levels: [Level::Frame, Level::Dom],
                range: false,
                expect: fn (CaseContext $context, Grid $grid): Expectation => $this->perMinute($context),
                consumed: ['per_minute'],
            ),
        ];
    }

    public function dom(): DomSpec
    {
        return new DomSpec(
            panel: self::PANEL,
            modes: [Mode::Off],
            expect: fn (CaseContext $context): array => [
                'Completed',
                $this->caption($context),
                Format::locale($this->counters($context)->count($context->fromMs, $context->toMs)),
            ],
            sumOf: 'A',
            format: static fn (float $value): string => Format::locale($value),
        );
    }

    /**
     * The completed attempts of the selection in (to - W, to], per minute, rounded; -1 (shown as a dash) without
     * any metric of the application.
     */
    public function rpm(CaseContext $context): float
    {
        if (! $this->metrics($context, $context->toMs)) {
            return -1.0;
        }

        $minutes = self::rpmMinutes($context->scrapeInterval());

        return Format::round($this->counters($context)->count($context->toMs - $minutes * 60_000, $context->toMs) / $minutes);
    }

    /**
     * W in minutes: max(5 m, 5 x S) (ADDENDUM R3.3).
     */
    public static function rpmMinutes(int $scrapeInterval): int
    {
        return max(5, (int) ceil(5 * $scrapeInterval / 60));
    }

    private function caption(CaseContext $context): string
    {
        $rpm = $this->rpm($context);

        return $rpm < 0 ? 'RPM: —' : 'RPM: '.(int) $rpm;
    }

    private function perStep(CaseContext $context, Grid $grid): Expectation
    {
        $counters = $this->counters($context);
        $total = $counters->count($context->fromMs, $context->toMs);
        $points = [];

        foreach ($counters->tileSteps($grid->times, $grid->stepMs, $context->fromMs, $context->toMs) as $time => $value) {
            // 0 while the application exports metrics (the zero fallback); no point without any metric
            if ($value !== null && $value !== 0.0) {
                $points[$time] = $value;
            } elseif ($this->metrics($context, $time)) {
                $points[$time] = 0.0;
            }
        }

        $this->crossCheck($context, $total);

        return new Expectation(
            series: [['labels' => [], 'points' => $points]],
            summary: sprintf('%d points on %s, sum C(from, to) = %s', count($points), $grid->step(), Format::locale($total)),
            total: $total,
        );
    }

    private function perMinute(CaseContext $context): Expectation
    {
        $rpm = $this->rpm($context);

        return new Expectation(
            series: [['labels' => [], 'points' => [$context->toMs => $rpm]]],
            summary: $rpm < 0 ? 'no metrics: -1 (RPM: —)' : sprintf('RPM %d over the last %d min', (int) $rpm, self::rpmMinutes($context->scrapeInterval())),
            dash: -1.0,
        );
    }

    private function counters(CaseContext $context): Counters
    {
        foreach (['prometheus_job', 'queue', 'job_class', 'batch_kind'] as $filter) {
            if ($context->selected($filter) !== null) {
                throw new LogicException(sprintf('#8 under a %s filter is not specified yet (WP6).', $filter));
            }
        }

        return new Counters(
            $context->store->metric(Metric::JobsTotal, ['status' => 'completed']),
            Counters::lookbackMs($context->scrapeInterval()),
        );
    }

    /**
     * Whether the application exports metrics at `$time` (its `horizon_status` is visible).
     */
    private function metrics(CaseContext $context, int $time): bool
    {
        foreach ($context->store->metric(Metric::HorizonStatus) as $series) {
            if (Visibility::instant($series, $time) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * C must equal the model's completed attempts visible in the period (an exporter mismatch otherwise).
     */
    private function crossCheck(CaseContext $context, float $total): void
    {
        $scrapes = [];

        foreach ($context->store->select('up') as $series) {
            foreach ($series['t'] as $index => $time) {
                if ($series['v'][$index] === 1.0) {
                    $scrapes[] = $time;
                }
            }
        }

        sort($scrapes);
        $visible = AttemptLog::visible($context->manifest, static fn (array $attempt): bool => $attempt['status'] === 'completed', $scrapes, $context->fromMs, $context->toMs);

        if ((float) $visible !== $total) {
            throw new RuntimeException(sprintf('exporter mismatch: the stored samples and the model disagree: the counter rule counts %s completed attempts in the period, the model %d visible ones (an exporter defect, or a defect of the counter rule or the visibility model of T17).', $total, $visible));
        }
    }
}
