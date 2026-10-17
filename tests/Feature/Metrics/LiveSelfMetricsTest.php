<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Exporter;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Support\Exposition;
use DF\HorizonObservability\Tests\Unit\Metrics\Support\FakeCollector;

pest()->group('redis');

/*
| Addendum R4.11, Q9' WP1: the live self-metrics are read from the storage `meta` hash and exported by the attempts
| collector (Add B'): ho_exporter_live_push_timestamp_seconds is absent until a stream leader has pushed once
| (meta.live_push_at), ho_exporter_live_push_failures_total appears once the stream has run at all.
|
| AttemptsCollector is T04's; until it lands, a reference reader with exactly that rule stands in for it, so the
| exposition contract (names, absence, float precision) is pinned here.
*/

const LIVE_SELF_METRICS_TEST_TOKEN = 'live-self-metrics-token-0123456789';

function liveSelfMetricsTestReader(): FakeCollector
{
    return new FakeCollector('attempts', static function (): array {
        [$pushedAt, $failures] = app(RedisClients::class)->storage()->hmget(app(Keys::class)->meta(), ['live_push_at', 'live_push_failures']);
        $families = [];

        if (is_numeric($pushedAt)) {
            $families[] = MetricFamily::of(Metric::LivePushTimestamp)->set([], (float) $pushedAt);
        }

        if (is_numeric($pushedAt) || is_numeric($failures)) {
            $families[] = MetricFamily::of(Metric::LivePushFailures)->set([], is_numeric($failures) ? (int) $failures : 0);
        }

        return $families;
    });
}

function liveSelfMetricsTestScrape(): string
{
    app()->instance(Exporter::class, new Exporter(app(), null, ['attempts' => liveSelfMetricsTestReader()]));

    return (string) test()->get('/horizon/observability/metrics', ['Authorization' => 'Bearer '.LIVE_SELF_METRICS_TEST_TOKEN])->assertOk()->getContent();
}

beforeEach(function (): void {
    config()->set('horizon-observability.token', LIVE_SELF_METRICS_TEST_TOKEN);
    $this->app->forgetInstance(Config::class);
});

test('the live series are absent until meta.live_push_at exists', function (): void {
    $meta = app(Keys::class)->meta();
    $storage = app(RedisClients::class)->storage();

    // A fresh install, or live mode never used: no line at all, not even HELP or TYPE.
    $never = liveSelfMetricsTestScrape();

    // A stream that has only failed so far (e.g. a 401 token): the failures counter shows it.
    $storage->hset($meta, 'live_push_failures', '3');
    $failing = Exposition::parse(liveSelfMetricsTestScrape());

    // The first successful push.
    $storage->hset($meta, 'live_push_at', '1792201716.123');
    $pushed = Exposition::parse(liveSelfMetricsTestScrape());

    expect($never)->not->toContain('ho_exporter_live_push')
        ->and(Exposition::parse($never)->value('ho_exporter_collector_success', ['collector' => 'attempts']))->toBe(1.0)
        ->and($failing->families())->not->toContain('ho_exporter_live_push_timestamp_seconds')
        ->and($failing->value('ho_exporter_live_push_failures_total'))->toBe(3.0)
        ->and($failing->types['ho_exporter_live_push_failures_total'] ?? null)->toBe('counter')
        ->and($pushed->value('ho_exporter_live_push_timestamp_seconds'))->toBe(1792201716.123)
        ->and($pushed->types['ho_exporter_live_push_timestamp_seconds'] ?? null)->toBe('gauge')
        ->and($pushed->value('ho_exporter_live_push_failures_total'))->toBe(3.0)
        ->and(liveSelfMetricsTestScrape())->toContain("\nho_exporter_live_push_timestamp_seconds 1792201716.123\n");
});
