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
| collector (Add B'). Both live series are absent until a stream leader has pushed once (meta.live_push_at exists):
| ho_exporter_live_push_timestamp_seconds is its value, ho_exporter_live_push_failures_total the failures counted so
| far (0 when none). Failures before the first push are kept in meta and show up with it.
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
            $families[] = MetricFamily::of(Metric::LivePushFailures)->set([], is_numeric($failures) ? (int) $failures : 0);
        }

        return $families;
    });
}

function liveSelfMetricsTestScrape(): string
{
    app()->instance(Exporter::class, new Exporter(app(), null, [...FakeCollector::quiet(), 'attempts' => liveSelfMetricsTestReader()]));

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

    // A stream that has only failed so far (e.g. a 401 token): still absent, as the stall rule expects (noData).
    $storage->hset($meta, 'live_push_failures', '3');
    $failing = liveSelfMetricsTestScrape();

    // The first successful push: both series, with the failures counted before it.
    $storage->hset($meta, 'live_push_at', '1792201716.123');
    $pushed = Exposition::parse(liveSelfMetricsTestScrape());

    // A push without any failure so far: the counter is 0, not absent.
    $storage->hdel($meta, 'live_push_failures');
    $clean = Exposition::parse(liveSelfMetricsTestScrape());

    expect($never)->not->toContain('ho_exporter_live_push')
        ->and(Exposition::parse($never)->value('ho_exporter_collector_success', ['collector' => 'attempts']))->toBe(1.0)
        ->and($failing)->not->toContain('ho_exporter_live_push')
        ->and($pushed->value('ho_exporter_live_push_timestamp_seconds'))->toBe(1792201716.123)
        ->and($pushed->types['ho_exporter_live_push_timestamp_seconds'] ?? null)->toBe('gauge')
        ->and($pushed->value('ho_exporter_live_push_failures_total'))->toBe(3.0)
        ->and($pushed->types['ho_exporter_live_push_failures_total'] ?? null)->toBe('counter')
        ->and($clean->value('ho_exporter_live_push_failures_total'))->toBe(0.0)
        ->and(liveSelfMetricsTestScrape())->toContain("\nho_exporter_live_push_timestamp_seconds 1792201716.123\n");
});
