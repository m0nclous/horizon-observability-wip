<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Compare;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Observation as Captured;

/**
 * The determinism check of two runs (design sections 4 and 9, `bin/run compare`): two observations of the same case
 * and version must be identical, apart from the measured durations.
 *
 * Compared, each in a run-independent form:
 * - per (panel, refId) the sent query (the frontend-interpolated expression or URL, the range and the step inputs);
 * - per (panel, refId) the frames of the last answer: field names, types and labels, and every row, with frames and
 *   rows order-insensitive (series order varies between loads);
 * - per panel key the DOM lines;
 * - per variable its value, text and options.
 *
 * Measured durations ({@see self::MEASURED}) are real time on every run: attempt durations (`hrtime`, the #6 F, G, I
 * and J columns) and Horizon's job runtimes and record stamps (the Repositories clock shim adds the real time since
 * the clock last moved, so the job lists' Runtime and their completed and failed times move by milliseconds). Their
 * frames are compared by shape (label sets, rows, times to the second) with the values masked, and duration cells of
 * their DOM are masked. Job and batch ids are random UUIDs (the job lists' links), so every UUID is masked too.
 * Everything else must be byte-identical.
 */
final class Determinism
{
    /**
     * panel id => refId => the measured number fields (`*`: every number field). Time fields of a job list are
     * compared to the second.
     */
    public const MEASURED = [
        '6' => ['F' => '*', 'G' => '*', 'I' => '*', 'J' => '*'],
        '18' => ['A' => ['Runtime']],
        '19' => ['A' => ['Runtime']],
    ];

    /**
     * A formatted duration cell: `871.30 µs`, `1.24 ms`, `0.00 s`, `2 min`.
     */
    private const DURATION = '/^-?\d[\d,]*(\.\d+)?\s?(ns|µs|ms|s|m|min|h)$/u';

    private const MASK = '~';

    /**
     * A job or batch id: Laravel draws them at random (`Str::uuid()`), so they differ between runs.
     */
    private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/';

    /**
     * Every difference between two observations of one case and version, human-readable.
     *
     * @return list<string>
     */
    public static function differences(Captured $first, Captured $second): array
    {
        $a = self::canonical($first);
        $b = self::canonical($second);
        $differences = [];

        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
            if (! array_key_exists($key, $a) || ! array_key_exists($key, $b)) {
                $differences[] = sprintf('%s: only in the %s run', $key, array_key_exists($key, $a) ? 'first' : 'second');
            } elseif ($a[$key] !== $b[$key]) {
                $differences[] = sprintf('%s differs', $key);
            }
        }

        return $differences;
    }

    /**
     * An observation in a run-independent form, by key.
     *
     * @return array<string, string>
     */
    public static function canonical(Captured $observation): array
    {
        $canonical = [];

        foreach ($observation->requests() as $request) {
            $panelId = (string) ($request['panelId'] ?? '?');
            $body = is_array($request['body'] ?? null) ? $request['body'] : [];

            foreach (is_array($body['queries'] ?? null) ? $body['queries'] : [] as $query) {
                if (! is_array($query)) {
                    continue;
                }

                $refId = (string) ($query['refId'] ?? '');
                $name = sprintf('#%s %s (%s)', $panelId, $refId, rawurldecode((string) ($request['panelTitle'] ?? '?')));
                $last = $observation->request($panelId, $refId);

                if ($last === null) {
                    $canonical['query '.$name] = 'unanswered';

                    continue;
                }

                $lastBody = is_array($last['body']) ? $last['body'] : [];
                $sent = [];

                foreach (is_array($lastBody['queries'] ?? null) ? $lastBody['queries'] : [] as $one) {
                    if (is_array($one) && ($one['refId'] ?? null) === $refId) {
                        $sent = array_intersect_key($one, array_flip(['expr', 'url', 'url_options', 'interval', 'intervalMs', 'maxDataPoints', 'range', 'instant', 'format']));
                    }
                }

                $canonical['query '.$name] = self::json([$lastBody['from'] ?? null, $lastBody['to'] ?? null, $sent]);
                $frames = Observation::frames(is_array($last['response']) ? $last['response'] : null, $refId);
                $canonical['frame '.$name] = self::frames($frames, self::MEASURED[$panelId][$refId] ?? null, isset(self::MEASURED[$panelId]));
            }
        }

        foreach ($observation->data['dom'] as $key => $dom) {
            $measured = isset(self::MEASURED[(string) preg_replace('/^panel-(\d+).*$/', '$1', (string) $key)]);
            $lines = array_map(static fn (string $line): string => $measured && preg_match(self::DURATION, $line) === 1 ? self::MASK : $line, $dom['lines']);
            $canonical['dom '.$key] = implode(' | ', $lines);
        }

        foreach ($observation->variables() as $name => $variable) {
            $canonical['variable '.$name] = self::json([$variable['value'], $variable['text'], $variable['options']]);
        }

        ksort($canonical);

        return array_map(static fn (string $text): string => (string) preg_replace(self::UUID, '<uuid>', $text), $canonical);
    }

    /**
     * The frames of one refId as sorted rows.
     *
     * @param  list<array<array-key, mixed>>  $frames
     * @param  '*'|list<string>|null  $measured  the measured number fields of the target
     * @param  bool  $seconds  whether time fields compare to the second (a panel with measured fields)
     */
    private static function frames(array $frames, string|array|null $measured, bool $seconds): string
    {
        $canonical = [];

        foreach ($frames as $frame) {
            $fields = is_array($frame['schema']['fields'] ?? null) ? array_values($frame['schema']['fields']) : [];
            $values = is_array($frame['data']['values'] ?? null) ? array_values($frame['data']['values']) : [];
            $entities = is_array($frame['data']['entities'] ?? null) ? array_values($frame['data']['entities']) : [];
            $header = [];
            $columns = [];

            foreach ($fields as $index => $field) {
                $field = is_array($field) ? $field : [];
                $type = (string) ($field['type'] ?? '');
                $name = (string) ($field['name'] ?? '');
                $labels = is_array($field['labels'] ?? null) ? $field['labels'] : [];
                ksort($labels);
                $header[] = [$name, $type, $labels];
                $column = is_array($values[$index] ?? null) ? array_values($values[$index]) : [];

                foreach (is_array($entities[$index] ?? null) ? $entities[$index] : [] as $entity => $positions) {
                    foreach (is_array($positions) ? $positions : [] as $position) {
                        $column[(int) $position] = $entity;
                    }
                }

                $masked = $type === 'number' && ($measured === '*' || (is_array($measured) && in_array($name, $measured, true)));
                $columns[] = array_map(static function (mixed $value) use ($masked, $seconds, $type): mixed {
                    if ($masked && $value !== null) {
                        return self::MASK;
                    }

                    return $seconds && $type === 'time' && is_numeric($value) ? intdiv((int) $value, 1000) : $value;
                }, $column);
            }

            $rows = [];
            $count = $columns === [] ? 0 : max(array_map(count(...), $columns));

            for ($row = 0; $row < $count; $row++) {
                $rows[] = self::json(array_map(static fn (array $column): mixed => $column[$row] ?? null, $columns));
            }

            sort($rows);
            $canonical[] = self::json($header)."\n".implode("\n", $rows);
        }

        sort($canonical);

        return implode("\n\n", $canonical);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '';
    }
}
