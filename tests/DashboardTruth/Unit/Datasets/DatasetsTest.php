<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\DashboardTruth\Datasets\Dataset;
use DF\HorizonObservability\Tests\DashboardTruth\Datasets\Datasets;

/*
| The epochs of the datasets (T17, design sections 4 and 6.4): every dataset starts at an E_k of its own and ends,
| with the dashboard's longest lookback, before the next one, so all of them can live in one Prometheus. The design
| gives core to quiet k = 0 to 5; the slice takes 6.
*/

pest()->group('grafana');

it('gives every dataset an epoch E_k of its own', function (): void {
    $ks = array_map(static function (Dataset $dataset): int {
        expect(($dataset->epoch() - Datasets::E_0) % Datasets::SPACING)->toBe(0, $dataset->name().' starts at no E_k')
            ->and($dataset->span() + 7 * 86400)->toBeLessThan(Datasets::SPACING, $dataset->name().' reaches the next epoch');

        return intdiv($dataset->epoch() - Datasets::E_0, Datasets::SPACING);
    }, array_values(Datasets::all()));

    expect($ks)->toBe(array_values(array_unique($ks)))
        ->and(Datasets::get('slice')->epoch())->toBe(Datasets::E_0 + 6 * Datasets::SPACING)
        ->and(gmdate('Y-m-d\TH:i:s\Z', Datasets::get('slice')->epoch()))->toBe('2025-04-20T00:00:00Z');
});
