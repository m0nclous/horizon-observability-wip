<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

/**
 * Prometheus text format 0.0.4 renderer (Arch Q1.1).
 *
 * - Families appear in {@see Metric} declaration order whatever the input order (so the self-metrics, declared
 *   last, come last), each with `# HELP` and `# TYPE`; empty families are omitted, and a non-empty output ends with
 *   one newline.
 * - Inside a family the series are sorted by their label values in catalog label order (byte order), so golden
 *   files are stable. A histogram series keeps its buckets in ascending `le` order, then `_sum` and `_count`.
 * - HELP escapes `\` and newline; label values escape `\`, `"` and newline. A label with an empty value is left
 *   out, which Prometheus treats the same (e.g. `horizon_info{tts_url}` without text-to-speech).
 * - Integers, and integral floats below 2^53, print as integers; other floats print in the shortest round-trip form
 *   of `var_export()` whatever the `serialize_precision` ini; `+Inf`, `-Inf` and `NaN` as such. `le` keeps the
 *   legacy spelling: `1`, `60`, `0.05`, `2.5`, `+Inf`.
 *
 * Every family must be given once: a second family of the same metric is a bug of the caller and throws
 * {@see InvalidMetric} (the exporter validates each collector's families before rendering them).
 *
 * @internal
 */
final class TextFormat
{
    /**
     * Largest magnitude below which an integral float is printed as an integer (2^53).
     */
    private const INTEGRAL_LIMIT = 9007199254740992.0;

    /**
     * @param  iterable<MetricFamily>  $families
     * @param  string  $prefix  `''` or `"<namespace>_"`
     */
    public function render(iterable $families, string $prefix): string
    {
        $byMetric = [];

        foreach ($families as $family) {
            $name = $family->metric()->value;

            if (isset($byMetric[$name])) {
                throw new InvalidMetric(sprintf('%s: the family is rendered twice; build it once.', $name));
            }

            $byMetric[$name] = $family;
        }

        $order = array_flip(array_map(static fn (Metric $metric): string => $metric->value, Metric::cases()));
        uksort($byMetric, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            $out = '';

            foreach ($byMetric as $family) {
                $out .= $this->family($family, $prefix);
            }

            return $out;
        } finally {
            ini_set('serialize_precision', $precision === false ? '-1' : $precision);
        }
    }

    /**
     * A HELP text with `\` and newline escaped.
     */
    public static function escapeHelp(string $help): string
    {
        return strtr($help, ['\\' => '\\\\', "\n" => '\\n']);
    }

    /**
     * A label value with `\`, `"` and newline escaped.
     */
    public static function escapeLabel(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']);
    }

    /**
     * A sample value: integers, and integral floats below 2^53, as integers; other floats in the shortest
     * round-trip form; `+Inf`, `-Inf`, `NaN`.
     */
    public static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        if (floor($value) === $value && abs($value) < self::INTEGRAL_LIMIT) {
            return (string) (int) $value;
        }

        $precision = ini_get('serialize_precision');

        if ($precision === '-1') {
            return var_export($value, true);
        }

        ini_set('serialize_precision', '-1');

        try {
            return var_export($value, true);
        } finally {
            ini_set('serialize_precision', $precision === false ? '-1' : $precision);
        }
    }

    /**
     * A bucket bound in the legacy spelling: an integral bound without a decimal (`1`, `60`), others in shortest
     * form (`0.05`, `2.5`), `+Inf` for the last bucket. Any numeric spelling of the bound is accepted.
     */
    public static function le(string $bound): string
    {
        if ($bound === '+Inf' || ! is_numeric($bound)) {
            return $bound;
        }

        $value = (float) $bound;

        return is_infinite($value) && $value > 0 ? '+Inf' : self::number($value);
    }

    private function family(MetricFamily $family, string $prefix): string
    {
        $samples = $family->samples();

        if ($samples === []) {
            return '';
        }

        $metric = $family->metric();
        $name = $prefix.$metric->value;
        $out = sprintf("# HELP %s %s\n# TYPE %s %s\n", $name, self::escapeHelp($metric->help()), $name, $metric->type()->value);

        foreach ($this->sorted($samples) as $sample) {
            $out .= $name.$sample->suffix.$this->labels($sample->labels).' '.self::number($sample->value)."\n";
        }

        return $out;
    }

    /**
     * Samples grouped by series (the labels without `le`), the groups sorted by their label values; the order
     * inside a group (buckets ascending, `_sum`, `_count`) is kept.
     *
     * @param  list<Sample>  $samples
     * @return list<Sample>
     */
    private function sorted(array $samples): array
    {
        $groups = [];

        foreach ($samples as $sample) {
            $series = $sample->labels;
            unset($series['le']);
            // Labels::sanitize() strips control characters, so "\x1f" never occurs inside a value and joining with
            // it sorts like an element-wise comparison of the values.
            $groups[implode("\x1f", $series)][] = $sample;
        }

        ksort($groups, SORT_STRING);

        return array_merge(...array_values($groups));
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function labels(array $labels): string
    {
        $pairs = [];

        foreach ($labels as $name => $value) {
            if ($value === '') {
                continue;
            }

            $pairs[] = $name.'="'.self::escapeLabel($name === 'le' ? self::le($value) : $value).'"';
        }

        return $pairs === [] ? '' : '{'.implode(',', $pairs).'}';
    }
}
