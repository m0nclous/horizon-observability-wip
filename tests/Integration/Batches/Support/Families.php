<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Integration\Batches\Support;

use DF\HorizonObservability\Metrics\MetricFamily;

/**
 * Collected families as plain arrays for assertions: family => `label="value",...` => value, series sorted.
 */
final class Families
{
    /**
     * @param  iterable<mixed>  $families
     * @return array<string, array<string, int|float>>
     */
    public static function of(iterable $families): array
    {
        $result = [];

        foreach ($families as $family) {
            if (! $family instanceof MetricFamily) {
                continue;
            }

            $series = [];

            foreach ($family->samples() as $sample) {
                $labels = [];

                foreach ($sample->labels as $name => $value) {
                    $labels[] = $name.'="'.$value.'"';
                }

                $series[implode(',', $labels)] = $sample->value;
            }

            ksort($series, SORT_STRING);
            $result[$family->metric()->value] = $series;
        }

        return $result;
    }
}
