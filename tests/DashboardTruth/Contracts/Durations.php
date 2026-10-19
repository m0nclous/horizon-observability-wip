<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

use InvalidArgumentException;

/**
 * Durations as Grafana and Prometheus print them (`15s`, `2m`, `1h30m`), and Grafana's ladder of rounded intervals
 * (the `roundInterval` steps of its backend), which the replays walk one step coarser (design section 5.6).
 */
final class Durations
{
    /**
     * Grafana's rounded intervals up to a day, milliseconds.
     */
    public const LADDER = [
        1_000, 2_000, 5_000, 10_000, 15_000, 20_000, 30_000, 60_000, 120_000, 300_000, 600_000, 900_000, 1_200_000,
        1_800_000, 3_600_000, 7_200_000, 10_800_000, 21_600_000, 43_200_000, 86_400_000,
    ];

    public static function parse(string $text): int
    {
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m(?!s))?(?:(\d+(?:\.\d+)?)s)?(?:(\d+)ms)?$/', $text, $match) !== 1 || $text === '') {
            throw new InvalidArgumentException(sprintf('"%s" is not a duration.', $text));
        }

        return (int) round(((int) ($match[1] ?? 0)) * 3_600_000 + ((int) ($match[2] ?? 0)) * 60_000 + ((float) ($match[3] ?? 0)) * 1000 + ((int) ($match[4] ?? 0)));
    }

    public static function format(int $milliseconds): string
    {
        if ($milliseconds % 1000 !== 0) {
            return $milliseconds.'ms';
        }

        $seconds = intdiv($milliseconds, 1000);
        $text = '';

        foreach (['h' => 3600, 'm' => 60, 's' => 1] as $unit => $size) {
            if ($seconds >= $size) {
                $text .= intdiv($seconds, $size).$unit;
                $seconds %= $size;
            }
        }

        return $text === '' ? '0s' : $text;
    }

    /**
     * The next coarser step of Grafana's ladder after `$stepMs`.
     */
    public static function coarser(int $stepMs): int
    {
        foreach (self::LADDER as $step) {
            if ($step > $stepMs) {
                return $step;
            }
        }

        throw new InvalidArgumentException(sprintf('No step coarser than %d ms.', $stepMs));
    }
}
