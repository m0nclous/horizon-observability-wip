<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Live\Stream\LiveStreamer;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Support\Exposition;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

/*
| Adversarial probe of the live path's clock: it reads Laravel's clock (`now()->toImmutable()`). Apps commonly
| switch Laravel's date class to CarbonImmutable (`Date::use()`) or run in a non-UTC timezone; /live, the exporter
| and the stream must still answer, agree with each other and stamp the same instant.
*/

pest()->group('redis');

beforeEach(function (): void {
    LiveState::freeze(LiveState::now());
    LiveState::horizon($this->app);
    LiveState::queues($this->app);
    LiveState::attempts();
    $this->app['env'] = 'local';
    $this->timezone = date_default_timezone_get();

    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana-qa3:3000');
    config()->set('horizon-observability.live.stream.token', 'glsa_qa3-token-0123456789');
    config()->set('horizon-observability.live.stream.id', 'probe');
    $this->app->forgetInstance(Config::class);

    $this->bodies = [];
    Http::fake(function (Request $request) {
        $this->bodies[] = $request->body();

        return Http::response('', 200);
    });
});

afterEach(function (): void {
    Date::useDefault();
    date_default_timezone_set($this->timezone);
    LiveState::freeze(null);
});

it('answers /live, the metrics and the stream alike whatever date class or timezone Laravel uses', function (Closure $configure): void {
    $configure();

    $metrics = Exposition::parse((string) $this->get('/horizon/observability/metrics')->assertOk()->getContent());
    $live = $this->getJson('/horizon/observability/live')->assertOk()->json();
    $tick = app()->make(LiveStreamer::class)->tick();
    $meta = RedisKeys::client()->hgetall(TestCase::redisPrefix().'horizon:'.(new Keys)->meta());

    $exported = [];

    foreach ($metrics->series('ho_queue_oldest_pending_job_age_seconds') as $key => $value) {
        preg_match('/queue="([^"]*)"/', $key, $match);
        $exported[$match[1]] = $value;
    }

    // default: the job pushed 17 s before NOW; 17 / 10 s -> level 1 (yellow); pending 3 + 2.
    expect($live['errors'])->toBe([])
        ->and($exported['default'])->toBe(17.0)
        ->and($live['generated_at'])->toBe(1792201716123)
        ->and($live['pending']['caption'])->toBe('Oldest: 17 s '."\u{00B7}".' default')
        ->and($tick)->toBe(LiveStreamer::PUSHED)
        ->and($this->bodies[0] ?? '')->toStartWith('tiles tile="pending",key="",caption="Oldest: 17 s '."\u{00B7}".' default",value=5.0,color="yellow",level=1.0 1792201716123000000')
        ->and(is_array($meta) ? ($meta['live_push_at'] ?? null) : null)->toBe('1792201716.123');
})->with([
    'Date::use(CarbonImmutable)' => [static function (): void {
        Date::use(CarbonImmutable::class);
    }],
    'a Date handler in Asia/Tokyo' => [static function (): void {
        Date::useCallable(static fn (CarbonInterface $date): CarbonInterface => $date->setTimezone('Asia/Tokyo'));
    }],
    'app timezone America/New_York' => [static function (): void {
        config()->set('app.timezone', 'America/New_York');
        date_default_timezone_set('America/New_York');
    }],
]);
