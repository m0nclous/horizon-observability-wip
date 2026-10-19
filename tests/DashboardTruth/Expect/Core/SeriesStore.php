<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Expect\Core;

use DF\HorizonObservability\Metrics\Metric;
use InvalidArgumentException;

/**
 * The samples Prometheus holds per series after the emulated scrape loop (design section 7.2): exactly what the
 * ingest writes, staleness markers included (a null value). The expectation engine reads only this store, never a
 * Prometheus query, so every expected value is computed from the stored samples by restated documented semantics.
 *
 * Series are keyed by their sorted label set (`__name__` included); samples are kept in time order per series
 * (milliseconds), as Prometheus requires for an append without an out-of-order window.
 */
final class SeriesStore
{
    /**
     * The metric namespace of the recorded expositions (the package default `ho`, owner decision 6).
     */
    public const PREFIX = 'ho_';

    /**
     * @var array<string, array{labels: array<string, string>, t: list<int>, v: list<float|null>}>
     */
    private array $series = [];

    /**
     * Append a sample (a null value is a staleness marker).
     *
     * @param  array<string, string>  $labels  with `__name__`
     */
    public function append(array $labels, int $time, ?float $value): void
    {
        $key = self::key($labels);
        $series = &$this->series[$key];

        if ($series === null) {
            ksort($labels, SORT_STRING);
            $series = ['labels' => $labels, 't' => [], 'v' => []];
        }

        $last = $series['t'] === [] ? null : $series['t'][count($series['t']) - 1];

        if ($last !== null && $time <= $last) {
            throw new InvalidArgumentException(sprintf('Out-of-order sample for %s: %d after %d.', $key, $time, $last));
        }

        $series['t'][] = $time;
        $series['v'][] = $value;
    }

    /**
     * Every series: key => labels and samples.
     *
     * @return array<string, array{labels: array<string, string>, t: list<int>, v: list<float|null>}>
     */
    public function all(): array
    {
        return $this->series;
    }

    /**
     * The series of a metric whose labels satisfy every matcher: label => exact value, or a callable(?string): bool
     * that receives the label value (null when absent).
     *
     * @param  array<string, string|callable(string|null): bool>  $matchers
     * @return array<string, array{labels: array<string, string>, t: list<int>, v: list<float|null>}>
     */
    public function select(string $name, array $matchers = []): array
    {
        $selected = [];

        foreach ($this->series as $key => $series) {
            if (($series['labels']['__name__'] ?? null) !== $name) {
                continue;
            }

            foreach ($matchers as $label => $matcher) {
                $value = $series['labels'][$label] ?? null;

                if (is_string($matcher) ? $value !== $matcher : ! $matcher($value)) {
                    continue 2;
                }
            }

            $selected[$key] = $series;
        }

        return $selected;
    }

    /**
     * The series of a package metric (`ho_<family>`), as {@see self::select()}.
     *
     * @param  array<string, string|callable(string|null): bool>  $matchers
     * @return array<string, array{labels: array<string, string>, t: list<int>, v: list<float|null>}>
     */
    public function metric(Metric $metric, array $matchers = []): array
    {
        return $this->select(self::PREFIX.$metric->value, $matchers);
    }

    /**
     * Every distinct sample time of the store, ascending (milliseconds).
     *
     * @return list<int>
     */
    public function times(): array
    {
        $times = [];

        foreach ($this->series as $series) {
            foreach ($series['t'] as $time) {
                $times[$time] = true;
            }
        }

        $times = array_keys($times);
        sort($times);

        return $times;
    }

    public function count(): int
    {
        $count = 0;

        foreach ($this->series as $series) {
            $count += count($series['t']);
        }

        return $count;
    }

    /**
     * The canonical key of a label set: sorted `name="value"` pairs, escaped as in the text exposition.
     *
     * @param  array<string, string>  $labels
     */
    public static function key(array $labels): string
    {
        ksort($labels, SORT_STRING);

        return implode(',', array_map(
            static fn (string $name, string $value): string => $name.'="'.addcslashes($value, "\\\"\n").'"',
            array_keys($labels),
            $labels,
        ));
    }
}
