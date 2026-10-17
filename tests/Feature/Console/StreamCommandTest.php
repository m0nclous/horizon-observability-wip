<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
| PUBLIC horizon-observability:stream (Addendum R4.6): the preconditions name the env var to set, --once pushes one
| snapshot (0, or 1 when nothing was pushed), --max-time ends the process with 0, and the token is never printed.
*/

pest()->group('redis');

const STREAM_COMMAND_TOKEN = 'glsa_command-token-0123456789';

beforeEach(function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana-a:3000');
    config()->set('horizon-observability.live.stream.token', STREAM_COMMAND_TOKEN);
    $this->app->forgetInstance(Config::class);
    LiveState::queues($this->app);
    $this->leader = TestCase::redisPrefix().'horizon:'.(new Keys)->liveLeader();
    $this->meta = TestCase::redisPrefix().'horizon:'.(new Keys)->meta();
});

function streamCommand(array $parameters = []): array
{
    $code = Artisan::call('horizon-observability:stream', $parameters);

    return [$code, Artisan::output()];
}

it('refuses to start without what it needs, naming the env var', function (array $config, string $message): void {
    foreach ($config as $key => $value) {
        config()->set('horizon-observability.'.$key, $value);
    }

    app()->forgetInstance(Config::class);
    Http::fake();

    [$code, $output] = streamCommand(['--once' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain($message);
    Http::assertNothingSent();
})->with([
    'live disabled' => [['live.enabled' => false], 'HORIZON_OBSERVABILITY_LIVE_ENABLED=true'],
    'no Grafana URL' => [['live.stream.grafana_url' => null], 'Set HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL'],
    'no valid Grafana URL' => [['live.stream.grafana_url' => 'ftp://grafana, not a url'], 'HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL has no valid http(s) URL'],
    'no token' => [['live.stream.token' => null], 'Set HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN'],
    'empty token' => [['live.stream.token' => ''], 'Set HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN'],
]);

it('rejects invalid limits', function (array $parameters, string $message): void {
    [$code, $output] = streamCommand($parameters);

    expect($code)->toBe(1)
        ->and($output)->toContain($message);
})->with([
    [['--max-time' => '-1'], '--max-time must be an integer of at least 0'],
    [['--max-time' => '1.5'], '--max-time must be an integer of at least 0'],
    [['--memory' => '0'], '--memory must be an integer of at least 1'],
    [['--memory' => 'lots'], '--memory must be an integer of at least 1'],
]);

it('pushes one snapshot with --once and gives the lease back', function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    [$code, $output] = streamCommand(['--once' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Streaming the live overview to http://grafana-a:3000 as stream horizon_observability every 1 s.')
        ->and($output)->toContain('Became the stream leader')
        ->and($output)->not->toContain(STREAM_COMMAND_TOKEN)
        ->and(RedisKeys::client()->hget($this->meta, 'live_push_at'))->not->toBeNull()
        ->and(RedisKeys::client()->exists($this->leader))->toBe(0);

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'http://grafana-a:3000/api/live/push/horizon_observability'
        && str_starts_with($request->body(), 'tiles tile="pending"'));
});

it('exits 1 from --once when Grafana refuses, with the hint and without the token', function (): void {
    Http::fake(['*' => Http::response('{"message":"invalid API key '.STREAM_COMMAND_TOKEN.'"}', 401)]);

    [$code, $output] = streamCommand(['--once' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('HTTP 401')
        ->and($output)->toContain('service account token invalid or missing; check HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN')
        ->and($output)->toContain('No push succeeded.')
        ->and($output)->not->toContain(STREAM_COMMAND_TOKEN)
        ->and(RedisKeys::client()->hget($this->meta, 'live_push_failures'))->toBe('1');
});

it('exits 1 from --once while another process leads', function (): void {
    Http::fake();
    RedisKeys::client()->set($this->leader, 'other-host:1:0000', 'PX', 5000);

    [$code, $output] = streamCommand(['--once' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('another stream process holds the leader lease')
        ->and(RedisKeys::client()->get($this->leader))->toBe('other-host:1:0000');
    Http::assertNothingSent();
});

it('stops after --max-time with exit code 0', function (): void {
    config()->set('horizon-observability.live.stream.interval', 0.5);
    app()->forgetInstance(Config::class);
    Http::fake(['*' => Http::response('', 200)]);
    $started = microtime(true);

    // --memory: the test process itself may use more than the default 128 MB
    [$code, $output] = streamCommand(['--max-time' => '1', '--memory' => '4096']);

    expect($code)->toBe(0)
        ->and(microtime(true) - $started)->toBeLessThan(3.0)
        ->and($output)->toContain('Stopping after --max-time=1 seconds.')
        ->and(RedisKeys::client()->exists($this->leader))->toBe(0)
        // ticks at 0, 0.5 and 1 s (a fourth when the third one came a hair early)
        ->and(count(Http::recorded()))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4);
});
