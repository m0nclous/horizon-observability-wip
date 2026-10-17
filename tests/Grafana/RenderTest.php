<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Json;
use DF\HorizonObservability\Grafana\Render;

/*
 * The render is a pure function of the options: the same bytes on every run and whatever the float ini settings,
 * and the JSON printer reads its own output back byte for byte (Arch Q5.1).
 */

pest()->group('grafana');

it('renders deterministically', function (): void {
    expect((new Render(new DashboardOptions))->files())->toBe((new Render(new DashboardOptions))->files());
});

it('renders the same bytes whatever the float ini settings', function (string $setting, string $value): void {
    $expected = (new Render(new DashboardOptions))->files();
    $previous = ini_get($setting);
    ini_set($setting, $value);

    try {
        expect((new Render(new DashboardOptions))->files())->toBe($expected);
    } finally {
        ini_set($setting, (string) $previous);
    }
})->with([
    ['precision', '17'],
    ['precision', '1'],
    ['serialize_precision', '17'],
]);

it('prints the decoded committed render back byte for byte', function (string $file): void {
    $committed = (string) file_get_contents(dirname(__DIR__, 2).'/resources/grafana/'.$file);

    expect(Json::encode(json_decode($committed, false, flags: JSON_THROW_ON_ERROR)))->toBe($committed);
})->with([Render::DASHBOARD, Render::ALERTING]);
