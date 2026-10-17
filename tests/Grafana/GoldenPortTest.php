<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\Json;
use DF\HorizonObservability\Grafana\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Resources;
use DF\HorizonObservability\Tests\Grafana\Support\JsonDiff;
use DF\HorizonObservability\Tests\Grafana\Support\LegacyFixtures;

/*
 * Arch Q5.6 M1: the PHP port renders the legacy dashboard exactly as
 * `python3 legacy/grafana/generate_queues_dashboard.py --prefix office` did.
 */

/**
 * A string as the dashboard JSON prints it.
 */
function jsonString(string $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
}

function renderLegacy(array $overrides = []): string
{
    return Json::encode((new DashboardBuilder(LegacyFixtures::options($overrides)))->build());
}

it('renders the legacy dashboard byte for byte', function (): void {
    $rendered = renderLegacy();
    $golden = LegacyFixtures::read('queues.json');

    // The first differing path makes a failure readable; the byte comparison is the gate
    expect(JsonDiff::first(json_decode($rendered), json_decode($golden)))->toBeNull()
        ->and($rendered)->toBe($golden);
});

it('decodes equal to the legacy dashboard with the key order preserved', function (): void {
    // The minimum gate of M1, independent of whitespace and escaping
    $canonical = static fn (string $json): string => json_encode(
        json_decode($json, false, flags: JSON_THROW_ON_ERROR),
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
    );

    expect($canonical(renderLegacy()))->toBe($canonical(LegacyFixtures::read('queues.json')));
});

it('prints the decoded golden back byte for byte', function (): void {
    $golden = LegacyFixtures::read('queues.json');

    expect(Json::encode(json_decode($golden, false, flags: JSON_THROW_ON_ERROR)))->toBe($golden);
});

it('embeds the alert sound script verbatim', function (): void {
    $script = LegacyFixtures::read('alert-sound.js');
    $panel = (new DashboardBuilder(LegacyFixtures::options()))->build()['panels'][0];

    expect($panel['id'])->toBe(PanelRegistry::ALERT_SOUND)
        ->and($panel['options']['afterRender'])->toBe($script)
        ->and($script)->not->toEndWith("\n");
});

it('renders deterministically', function (): void {
    expect(renderLegacy())->toBe(renderLegacy());
});

it('renders the same bytes whatever the float ini settings', function (string $setting, string $value): void {
    $previous = ini_get($setting);
    ini_set($setting, $value);

    try {
        expect(renderLegacy())->toBe(LegacyFixtures::read('queues.json'));
    } finally {
        ini_set($setting, (string) $previous);
    }
})->with([
    ['precision', '17'],
    ['precision', '1'],
    ['serialize_precision', '17'],
]);

it('takes the legacy literals from the options', function (array $overrides, Closure $expected): void {
    expect(renderLegacy($overrides))->toBe($expected(LegacyFixtures::read('queues.json')));
})->with([
    // The legacy generator's --prefix rewrote exactly these names in the finished JSON
    'metric prefix' => [
        ['metricPrefix' => 'acme_'],
        fn (string $golden): string => (string) preg_replace('/\boffice_(?=(?:queue|horizon)_)/', 'acme_', $golden),
    ],
    'uid' => [['uid' => 'queues'], fn (string $golden): string => str_replace('"uid": "horizon"', '"uid": "queues"', $golden)],
    'title' => [['title' => 'Horizon'], fn (string $golden): string => str_replace('"title": "Queues"', '"title": "Horizon"', $golden)],
    'tags' => [
        ['tags' => ['horizon', 'queue']],
        fn (string $golden): string => str_replace("\"queue\",\n    \"office\"\n", "\"queue\"\n", $golden),
    ],
    'Infinity job list url' => [
        ['jobListUrl' => '${api_path}/jobs/{list}'],
        fn (string $golden): string => (string) preg_replace('#/metrics/horizon/(\w+)-jobs\?#', '${api_path}/jobs/$1?', $golden),
    ],
    'busy window' => [['busyWindow' => '30s'], fn (string $golden): string => str_replace('[15s]', '[30s]', $golden)],
    // Arch Q5.6 step 1: the English script of resources/grafana/js changes only panel #23 afterRender
    'alert sound script' => [
        ['alertSoundScript' => Resources::alertSoundScript()],
        fn (string $golden): string => str_replace(jsonString(LegacyFixtures::read('alert-sound.js')), jsonString(Resources::alertSoundScript()), $golden),
    ],
    'refresh' => [['refresh' => '30s'], fn (string $golden): string => str_replace('"refresh": "15s"', '"refresh": "30s"', $golden)],
    'alert rule selector' => [
        ['alertRuleUidRegex' => 'queues-(down|failures)'],
        fn (string $golden): string => str_replace('grafana_rule_uid=~\"horizon-.*\"', 'grafana_rule_uid=~\"queues-(down|failures)\"', $golden),
    ],
    'datasource regexes' => [
        ['prometheusDatasourceRegex' => '/Prometheus/', 'infinityDatasourceRegex' => '/App/'],
        fn (string $golden): string => str_replace(
            ["\"query\": \"prometheus\",\n        \"refresh\": 1,\n        \"regex\": \"\"", "\"query\": \"yesoreyeram-infinity-datasource\",\n        \"refresh\": 1,\n        \"regex\": \"\""],
            ["\"query\": \"prometheus\",\n        \"refresh\": 1,\n        \"regex\": \"/Prometheus/\"", "\"query\": \"yesoreyeram-infinity-datasource\",\n        \"refresh\": 1,\n        \"regex\": \"/App/\""],
            $golden,
        ),
    ],
]);

it('rejects options the renderer does not support yet', function (array $overrides, string $option): void {
    expect(fn (): string => renderLegacy($overrides))->toThrow(LogicException::class, $option);
})->with([
    'live poll' => [['live' => LiveMode::Poll], 'live'],
    'live stream' => [['live' => LiveMode::Stream], 'live'],
    'job scope' => [['job' => 'app'], 'job'],
    'dedupe' => [['dedupe' => true], 'dedupe'],
]);
