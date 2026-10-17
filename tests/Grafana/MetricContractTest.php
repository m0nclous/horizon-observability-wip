<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Arch Q5.7 item 2, the families: every <ns>_<name> in the committed dashboard and alert rules is a family of the
 * catalog (Metrics\Metric), after stripping the histogram suffixes _bucket, _sum and _count.
 */

pest()->group('grafana');

it('reads only families of the metric catalog', function (): void {
    $families = array_map(static fn (Metric $metric): string => $metric->value, Metric::cases());
    $unknown = [];
    $text = implode("\n", Rendered::expressions())."\n".json_encode(Rendered::alerting(), JSON_THROW_ON_ERROR);

    preg_match_all('/\bho_([a-z_]+)/', $text, $matches);

    foreach (array_unique($matches[1]) as $name) {
        $family = (string) preg_replace('/_(bucket|sum|count)$/', '', $name);

        if (! in_array($family, $families, true) && ! in_array($name, $families, true)) {
            $unknown[] = 'ho_'.$name;
        }
    }

    expect($matches[1])->not->toBe([])
        ->and($unknown)->toBe([]);
});

it('no longer reads the pilot-only families', function (): void {
    $text = json_encode([Rendered::dashboard(), Rendered::alerting()], JSON_THROW_ON_ERROR);

    expect($text)->not->toContain('horizon_current_processes')
        ->and(preg_match('/queue_oldest_pending_job_age(?!_seconds)/', $text))->toBe(0);
});
