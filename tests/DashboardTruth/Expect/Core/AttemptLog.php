<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Expect\Core;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Manifest;

/**
 * The cross-check of the counter rule against the ground truth (design section 7.2): C equals the number of model
 * attempts in the selection whose visibility time lies in (baseline time, last sample <= to], when no registered
 * deviation applies. An attempt is visible at the first successful scrape after it at which its series is no longer
 * held, i.e. at least 90 s (`AttemptsCollector::NEW_SERIES_HOLD_SECONDS`, T16) after the series' first attempt.
 *
 * WP1 checks it for a single target without gaps; WP5 extends it to the datasets' gaps and replicas.
 */
final class AttemptLog
{
    public const HOLD_SECONDS = 90;

    /**
     * The model's attempts of the selection visible in (from, to]: their count.
     *
     * @param  callable(array{connection: string, queue: string, class: string, status: string, at: float}): bool  $selected
     * @param  list<int>  $scrapes  the successful scrape times of the target, ascending, milliseconds
     */
    public static function visible(Manifest $manifest, callable $selected, array $scrapes, int $fromMs, int $toMs): int
    {
        $births = [];

        foreach ($manifest->attempts as $attempt) {
            $series = $attempt['connection'].'|'.$attempt['queue'].'|'.$attempt['class'];
            $births[$series] = min($births[$series] ?? INF, $attempt['at']);
        }

        $count = 0;

        foreach ($manifest->attempts as $attempt) {
            if (! $selected($attempt)) {
                continue;
            }

            $release = $births[$attempt['connection'].'|'.$attempt['queue'].'|'.$attempt['class']] + self::HOLD_SECONDS;
            $visible = null;

            foreach ($scrapes as $scrape) {
                if ($scrape / 1000 > $attempt['at'] && $scrape / 1000 >= $release) {
                    $visible = $scrape;

                    break;
                }
            }

            if ($visible !== null && $visible > $fromMs && $visible <= $toMs) {
                $count++;
            }
        }

        return $count;
    }
}
