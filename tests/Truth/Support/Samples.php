<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Truth\Support;

use DF\HorizonObservability\Tests\Support\Exposition;

/**
 * Reads a scraped exposition in the shapes of the {@see Model}: label sets keyed by {@see Model::key()}, integral
 * counters, histograms with their cumulative buckets, count and sum.
 */
final readonly class Samples
{
    public const NAMESPACE = 'ho_';

    public function __construct(public Exposition $exposition) {}

    /**
     * Every sample of a family (without the namespace), labels key => value.
     *
     * @return array<string, float>
     */
    public function series(string $family): array
    {
        $series = $this->exposition->series(self::NAMESPACE.$family);
        ksort($series);

        return $series;
    }

    /**
     * The samples of a counter or integral gauge as integers.
     *
     * @return array<string, int>
     */
    public function integers(string $family): array
    {
        return array_map(static fn (float $value): int => (int) $value, $this->series($family));
    }

    public function value(string $family, array $labels = []): ?float
    {
        return $this->exposition->value(self::NAMESPACE.$family, $labels);
    }

    /**
     * A histogram family: series key (without `le`) => cumulative buckets (`le` => count), count and sum.
     *
     * @return array<string, array{buckets: array<string, int>, count: int, sum: float}>
     */
    public function histograms(string $family): array
    {
        $histograms = [];

        foreach ($this->series($family.'_bucket') as $key => $value) {
            $labels = self::labels($key);
            $le = $labels['le'] ?? '';
            unset($labels['le']);
            $histograms[Model::key($labels)]['buckets'][$le] = (int) $value;
        }

        foreach ($histograms as &$histogram) {
            uksort($histogram['buckets'], static fn (int|string $a, int|string $b): int => ($a === '+Inf' ? INF : (float) $a) <=> ($b === '+Inf' ? INF : (float) $b));
        }

        unset($histogram);

        foreach ($this->series($family.'_count') as $key => $value) {
            $histograms[$key]['count'] = (int) $value;
        }

        foreach ($this->series($family.'_sum') as $key => $value) {
            $histograms[$key]['sum'] = $value;
        }

        ksort($histograms);

        /** @var array<string, array{buckets: array<string, int>, count: int, sum: float}> $histograms */
        return $histograms;
    }

    /**
     * Sum of a family's samples grouped by one label.
     *
     * @return array<string, float>
     */
    public function sumBy(string $family, string $label): array
    {
        $sums = [];

        foreach ($this->series($family) as $key => $value) {
            $name = self::labels($key)[$label] ?? '';
            $sums[$name] = ($sums[$name] ?? 0.0) + $value;
        }

        ksort($sums);

        return $sums;
    }

    /**
     * The label sets of a family's samples.
     *
     * @return list<array<string, string>>
     */
    public function labelSets(string $family): array
    {
        return array_map(self::labels(...), array_keys($this->series($family)));
    }

    /**
     * Families with a TYPE line, without the namespace.
     *
     * @return list<string>
     */
    public function families(): array
    {
        return array_map(static fn (string $family): string => substr($family, strlen(self::NAMESPACE)), $this->exposition->families());
    }

    /**
     * The labels of a labels key (`a="1",b="x\"y"`).
     *
     * @return array<string, string>
     */
    public static function labels(string $key): array
    {
        preg_match_all('/(\w+)="((?:[^"\\\\]|\\\\.)*)"/', $key, $matches, PREG_SET_ORDER);
        $labels = [];

        foreach ($matches as $match) {
            $labels[$match[1]] = strtr($match[2], ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n"]);
        }

        return $labels;
    }
}
