<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

/**
 * Per-scrape builder of one family's samples (Arch Q1.2). Every call is validated, and any violation throws
 * {@see InvalidMetric}, which the exporter counts as a failure of the collector:
 * - the label keys must equal {@see Metric::labels()} as a set (`le` is reserved for histogram buckets);
 * - values pass through {@see Labels::sanitize()};
 * - a label set may occur once per family, so collectors aggregate first;
 * - `set()` is for counters and gauges, `observe()` for histograms; counters are never negative or NaN;
 * - histogram buckets are keyed by `le`, strictly ascending, with non-decreasing counts, and end with `+Inf`,
 *   which is also `_count`.
 *
 * {@see self::samples()} returns the series in insertion order; a histogram series is its buckets in ascending
 * order, then `_sum`, then `_count`. Sorting is the renderer's job.
 *
 * @internal
 */
final class MetricFamily
{
    /**
     * @var list<Sample>
     */
    private array $samples = [];

    /**
     * @var array<string, true>
     */
    private array $seen = [];

    private function __construct(private readonly Metric $metric) {}

    public static function of(Metric $metric): self
    {
        return new self($metric);
    }

    /**
     * Add a counter or gauge sample.
     *
     * @param  array<string, string>  $labels
     */
    public function set(array $labels, int|float $value): self
    {
        $type = $this->metric->type();

        if ($type === MetricType::Histogram) {
            throw new InvalidMetric(sprintf('%s is a histogram; use observe().', $this->metric->value));
        }

        if ($type === MetricType::Counter && (is_nan((float) $value) || $value < 0)) {
            throw new InvalidMetric(sprintf('%s is a counter and cannot be negative or NaN.', $this->metric->value));
        }

        $this->samples[] = new Sample('', $this->series($labels), $value);

        return $this;
    }

    /**
     * Add one histogram series.
     *
     * @param  array<string, string>  $labels
     * @param  array<string, int>  $cumulativeBuckets  le => cumulative count, ascending, '+Inf' last
     */
    public function observe(array $labels, array $cumulativeBuckets, float $sum): self
    {
        if ($this->metric->type() !== MetricType::Histogram) {
            throw new InvalidMetric(sprintf('%s is a %s; use set().', $this->metric->value, $this->metric->type()->value));
        }

        $series = $this->series($labels);
        $buckets = $this->buckets($cumulativeBuckets);

        foreach ($buckets as [$le, $count]) {
            $this->samples[] = new Sample('_bucket', [...$series, 'le' => $le], $count);
        }

        $this->samples[] = new Sample('_sum', $series, $sum);
        $this->samples[] = new Sample('_count', $series, $buckets[array_key_last($buckets)][1]);

        return $this;
    }

    public function metric(): Metric
    {
        return $this->metric;
    }

    /**
     * @return list<Sample>
     */
    public function samples(): array
    {
        return $this->samples;
    }

    public function isEmpty(): bool
    {
        return $this->samples === [];
    }

    /**
     * Validated, sanitised labels in catalog order; registers the label set.
     *
     * @param  array<array-key, mixed>  $labels
     * @return array<string, string>
     */
    private function series(array $labels): array
    {
        $names = $this->metric->labels();
        $given = array_map(strval(...), array_keys($labels));

        if (in_array('le', $given, true)) {
            throw new InvalidMetric(sprintf('%s: the label "le" is reserved for histogram buckets.', $this->metric->value));
        }

        $missing = array_diff($names, $given);
        $unknown = array_diff($given, $names);

        if ($missing !== [] || $unknown !== []) {
            throw new InvalidMetric(sprintf(
                '%s expects the labels [%s], got [%s].',
                $this->metric->value,
                implode(', ', $names),
                implode(', ', $given),
            ));
        }

        $series = [];

        foreach ($names as $name) {
            $value = $labels[$name];

            if (! is_string($value)) {
                throw new InvalidMetric(sprintf('%s: the value of label "%s" must be a string, %s given.', $this->metric->value, $name, get_debug_type($value)));
            }

            $series[$name] = Labels::sanitize($value);
        }

        $key = implode("\x1f", $series);

        if (isset($this->seen[$key])) {
            throw new InvalidMetric(sprintf('%s: duplicate label set {%s}; aggregate before adding.', $this->metric->value, $this->describe($series)));
        }

        $this->seen[$key] = true;

        return $series;
    }

    /**
     * @param  array<array-key, mixed>  $buckets
     * @return non-empty-list<array{0: string, 1: int}>
     */
    private function buckets(array $buckets): array
    {
        $validated = [];
        $previousBound = -INF;
        $previousCount = 0;

        foreach ($buckets as $le => $count) {
            $le = (string) $le;
            $bound = $le === '+Inf' ? INF : (is_numeric($le) ? (float) $le : null);

            if ($bound === null || is_nan($bound)) {
                throw new InvalidMetric(sprintf('%s: bucket bound "%s" is not a number or +Inf.', $this->metric->value, $le));
            }

            if ($bound <= $previousBound) {
                throw new InvalidMetric(sprintf('%s: bucket bounds must be strictly ascending ("%s").', $this->metric->value, $le));
            }

            if (! is_int($count) || $count < $previousCount) {
                throw new InvalidMetric(sprintf('%s: bucket counts must be cumulative integers ("%s").', $this->metric->value, $le));
            }

            $validated[] = [$le, $count];
            $previousBound = $bound;
            $previousCount = $count;
        }

        if ($validated === [] || $previousBound !== INF) {
            throw new InvalidMetric(sprintf('%s: the last bucket must be "+Inf".', $this->metric->value));
        }

        return $validated;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function describe(array $labels): string
    {
        return implode(', ', array_map(
            static fn (string $name, string $value): string => $name.'="'.$value.'"',
            array_keys($labels),
            $labels,
        ));
    }
}
