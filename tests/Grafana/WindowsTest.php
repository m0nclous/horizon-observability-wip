<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Scrape-interval agnostic windows (Arch Q5.2, Q5.6 steps 6 and 12, ADDENDUM R3.3): the worker utilisation reads the
 * hidden busy_window textbox instead of the pilot's literal 15 s window.
 */

pest()->group('grafana');

it('reads the utilisation window from the hidden busy_window variable', function (): void {
    $expressions = implode("\n", Rendered::expressions());
    $variables = array_column(Rendered::dashboard()['templating']['list'], null, 'name');

    expect($expressions)->not->toContain('[15s]')
        ->toContain('[$busy_window]')
        ->and(substr_count($expressions, '[$busy_window]'))->toBe(substr_count($expressions, 'sum_over_time(ho_queue_jobs_reserved') * 2)
        ->and($variables['busy_window'])->toMatchArray([
            'hide' => 2,
            'type' => 'textbox',
            'query' => '15s',
            'current' => ['text' => '15s', 'value' => '15s'],
        ]);
});
