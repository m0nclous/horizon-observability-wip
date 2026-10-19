<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Live\Stream\LiveStreamer;
use DF\HorizonObservability\Tests\Integration\Live\Stream\Support\StreamClock;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Adversarial probes of the stream loop on a fake clock where a push takes time, as it does against a real Grafana: the
| backoff schedule (1, 2, 4, 8, 15 s) and the release after 5 failed ticks must not drift by one tick per failure.
*/

pest()->group('redis');

beforeEach(function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana-qa:3000');
    config()->set('horizon-observability.live.stream.token', 'glsa_qa-token-0123456789');
    config()->set('horizon-observability.live.stream.id', 'probe');
    config()->set('horizon-observability.live.cache_ttl', 0);
    $this->app->forgetInstance(Config::class);

    $this->time = new StreamClock(LiveState::now());
    LiveState::horizon($this->app);
    LiveState::queues($this->app);

    $this->pushes = [];
    $this->answers = [];
    Http::fake(function (Request $request) {
        $this->pushes[] = $this->time->seconds();
        // A refused push still costs a round trip to Grafana.
        $this->time->advance(0.05);

        return Http::response('{"message":"refused"}', array_shift($this->answers) ?? 200);
    });
});

afterEach(function (): void {
    LiveState::freeze(null);
});

function qaStreamer(): LiveStreamer
{
    $time = test()->time;

    return app()->make(LiveStreamer::class, ['clock' => $time->clock(), 'sleep' => $time->sleep()]);
}

it('retries a refused push after 1, 2, 4 and 8 s when each push takes 50 ms', function (): void {
    $this->answers = [500, 500, 500, 500, 500];

    qaStreamer()->run(static fn (): bool => false, 25, 4096, false);

    // Each retry is due `backoff` seconds after the failed push; the tick loop may round it up to the next tick,
    // which is at most one interval (1 s) late, but never a whole extra second per failure.
    // (The 6th push is the lease taken again 10 s after the release at the 5th failed tick, 15 + 10 = 25 s, inside
    // --max-time=25; the late schedule released at 19 s and competed again at 29 s, after the run.)
    expect($this->pushes)->toHaveCount(6)
        ->and($this->pushes)->toBe([0.0, 1.0, 3.0, 7.0, 15.0, 25.0])
        ->and($this->pushes[1] - $this->pushes[0])->toBeLessThan(1.5)
        ->and($this->pushes[2] - $this->pushes[1])->toBeLessThan(2.5)
        ->and($this->pushes[3] - $this->pushes[2])->toBeLessThan(4.5)
        ->and($this->pushes[4] - $this->pushes[3])->toBeLessThan(8.5);
});
