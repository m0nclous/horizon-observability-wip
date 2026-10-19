<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Compare;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Durations;

/**
 * Reads Grafana's data frames out of an `/api/ds/query` response (captured or replayed) in the comparator's terms:
 * series by label set with their points, the executed query and step (`meta.executedQueryString`, `Expr: ...\nStep:
 * 15s`, design section 5.3) and the grid, which is Grafana's choice and never computed.
 *
 * A frame is `schema.fields` (a time field, then number fields with labels) and `data.values` (one column per
 * field); `data.entities` marks NaN and +-Inf positions that JSON cannot carry.
 */
final class Observation
{
    /**
     * The frames of one refId of a response.
     *
     * @param  array<array-key, mixed>|null  $response
     * @return list<array<array-key, mixed>>
     */
    public static function frames(?array $response, string $refId): array
    {
        $frames = $response['results'][$refId]['frames'] ?? [];

        return is_array($frames) ? array_values(array_filter($frames, is_array(...))) : [];
    }

    /**
     * The error of one refId's result, or null.
     *
     * @param  array<array-key, mixed>|null  $response
     */
    public static function error(?array $response, string $refId): ?string
    {
        $result = $response['results'][$refId] ?? null;

        if (! is_array($result)) {
            return 'no result for '.$refId;
        }

        if (isset($result['error']) && $result['error'] !== '') {
            return (string) (is_scalar($result['error']) ? $result['error'] : json_encode($result['error']));
        }

        $status = $result['status'] ?? 200;

        return is_int($status) && $status >= 400 ? 'status '.$status : null;
    }

    /**
     * Every number field of the frames as a series: labels and points (time => value; NaN and +-Inf restored).
     *
     * @param  list<array<array-key, mixed>>  $frames
     * @return list<array{labels: array<string, string>, points: array<int, float|null>}>
     */
    public static function series(array $frames): array
    {
        $series = [];

        foreach ($frames as $frame) {
            $fields = is_array($frame['schema']['fields'] ?? null) ? $frame['schema']['fields'] : [];
            $values = is_array($frame['data']['values'] ?? null) ? $frame['data']['values'] : [];
            $entities = is_array($frame['data']['entities'] ?? null) ? $frame['data']['entities'] : [];
            $timeIndex = null;

            foreach ($fields as $index => $field) {
                if (is_array($field) && ($field['type'] ?? null) === 'time') {
                    $timeIndex = $index;

                    break;
                }
            }

            foreach ($fields as $index => $field) {
                if (! is_array($field) || ($field['type'] ?? null) !== 'number') {
                    continue;
                }

                $column = is_array($values[$index] ?? null) ? $values[$index] : [];
                $special = is_array($entities[$index] ?? null) ? $entities[$index] : [];

                foreach (['NaN' => NAN, 'Inf' => INF, 'NegInf' => -INF] as $name => $value) {
                    foreach (is_array($special[$name] ?? null) ? $special[$name] : [] as $position) {
                        $column[(int) $position] = $value;
                    }
                }

                $times = $timeIndex === null ? [] : (is_array($values[$timeIndex] ?? null) ? $values[$timeIndex] : []);
                $points = [];

                foreach ($column as $row => $value) {
                    $time = $times[$row] ?? null;

                    if (is_int($time) || is_float($time)) {
                        $points[(int) $time] = $value === null ? null : (float) $value;
                    }
                }

                ksort($points);
                $labels = is_array($field['labels'] ?? null) ? array_map(strval(...), $field['labels']) : [];
                ksort($labels);
                $series[] = ['labels' => $labels, 'points' => $points];
            }
        }

        return $series;
    }

    /**
     * `meta.executedQueryString` of the first frame (Grafana adds it to every Prometheus frame).
     *
     * @param  list<array<array-key, mixed>>  $frames
     */
    public static function executed(array $frames): ?string
    {
        foreach ($frames as $frame) {
            $executed = $frame['schema']['meta']['executedQueryString'] ?? null;

            if (is_string($executed)) {
                return $executed;
            }
        }

        return null;
    }

    /**
     * The step the backend chose, from `Step: <duration>` of the executed query string, in milliseconds.
     *
     * @param  list<array<array-key, mixed>>  $frames
     */
    public static function step(array $frames): ?int
    {
        $executed = self::executed($frames);

        if ($executed === null || preg_match('/\nStep: (\S+)\s*$/', $executed, $match) !== 1) {
            return null;
        }

        return Durations::parse($match[1]);
    }

    /**
     * Grafana's grid of a range target (design section 5.3): every multiple of the step at the phase of the returned
     * points, from the one in (from - step, from] to the one in (to - step, to]. Grafana's backend aligns the start
     * and the end of the range query down to the step; the phase (0, or the zone offset of a relative range, design
     * section 5.5) is read from the earliest returned point, never computed. A point the dashboard leaves out (a gap,
     * a cut start or end) is therefore a missing point of the comparison, never a smaller grid, and a point off the
     * grid is an unexpected one. Without any returned point the phase is 0 (an absolute range, or UTC).
     *
     * @param  list<array<array-key, mixed>>  $frames
     * @return list<int>
     */
    public static function grid(array $frames, int $stepMs, int $fromMs, int $toMs): array
    {
        if ($stepMs <= 0 || $toMs < $fromMs) {
            return [];
        }

        $earliest = null;

        foreach (self::series($frames) as $series) {
            foreach (array_keys($series['points']) as $time) {
                $earliest = $earliest === null ? $time : min($earliest, $time);
            }
        }

        $phase = $earliest === null ? 0 : self::modulo($earliest, $stepMs);
        $first = $fromMs - self::modulo($fromMs - $phase, $stepMs);
        $last = $toMs - self::modulo($toMs - $phase, $stepMs);

        return $last < $first ? [] : range($first, $last, $stepMs);
    }

    private static function modulo(int $value, int $divisor): int
    {
        return (($value % $divisor) + $divisor) % $divisor;
    }

    /**
     * The expression part of an executed query string.
     */
    public static function expression(?string $executed): ?string
    {
        if ($executed === null) {
            return null;
        }

        return preg_replace('/^Expr: |\nStep: \S+\s*$/', '', $executed);
    }
}
