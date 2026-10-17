<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Live\Captions;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Support\Exposition;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;

/*
| Adversarial probes of GET {base}/live (T12 QA round 2): the round 1 fixes (a Supervisor filter while Horizon is
| stopped, the wait level from the exact age, names that are not valid UTF-8) and the numbers next to them, against
| the exporter and the off-mode tile rules for the same Redis state (owner decision 13).
*/

pest()->group('redis');

beforeEach(function (): void {
    CarbonImmutable::setTestNow(LiveState::now());
    $this->app['env'] = 'local'; // every package endpoint is open in local without configuration
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * The off-mode Pending caption for an exported oldest age (Tiles::pending(): `round(age)` + " s" below 120 s, else
 * `round(age / 60)` + " min", both on the exported value).
 */
function qa2OffModeOldest(float $age, string $queue): string
{
    return $age < 120
        ? sprintf('Oldest: %d s %s %s', (int) round($age), Captions::DOT, $queue)
        : sprintf('Oldest: %d min %s %s', (int) round($age / 60), Captions::DOT, $queue);
}

/**
 * The live caption and the off-mode caption of one queue whose oldest ready job waits `$age` seconds.
 *
 * @return array{live: string, offMode: string}
 */
function qa2Captions(float $age): array
{
    LiveState::horizon(app());
    QueuePayloads::push(LiveState::redis(app()), 'default', LiveState::job('App\\Jobs\\SendInvoice', LiveState::NOW - $age));

    $metrics = Exposition::parse((string) test()->get('/horizon/observability/metrics')->assertOk()->getContent());
    $exported = (float) $metrics->value('ho_queue_oldest_pending_job_age_seconds', ['connection' => 'redis', 'queue' => 'default']);
    $live = test()->getJson('/horizon/observability/live?var-queue=default')->assertOk()->json();

    return ['live' => $live['pending']['caption'], 'offMode' => qa2OffModeOldest($exported, 'default')];
}

it('captions an oldest wait below two minutes like the off-mode tile', function (): void {
    expect(qa2Captions(89.6))->toBe(['live' => 'Oldest: 90 s '.Captions::DOT.' default', 'offMode' => 'Oldest: 90 s '.Captions::DOT.' default']);
});

it('captions the oldest wait like the off-mode tile, from the exact age', function (float $age, string $caption): void {
    expect(qa2Captions($age))->toBe(['live' => $caption, 'offMode' => $caption]);
})->with([
    // live rounds to 120 s first and switches to minutes: "2 min"
    '119.6 s' => [119.6, 'Oldest: 120 s '.Captions::DOT.' default'],
    // live rounds twice (150 s, then 2.5 min): "3 min" for 2.49 min
    '149.6 s' => [149.6, 'Oldest: 2 min '.Captions::DOT.' default'],
    // 210 s, then 3.5 min: "4 min" for 3.495 min
    '209.7 s' => [209.7, 'Oldest: 3 min '.Captions::DOT.' default'],
])->skip('T12 QA round 2 finding 2: the caption is derived from the age rounded to seconds (and the minutes rounded again), so 119.6 s reads "2 min", 149.6 s "3 min" and 209.7 s "4 min" where the off-mode tile reads "120 s", "2 min" and "3 min"; unskip after the fix.');

it('scopes a supervisor that only horizon.defaults defines by its queues while Horizon is stopped', function (): void {
    // Horizon's ProvisioningPlan merges horizon.defaults into every environment, so `supervisor-extra` starts in
    // `testing` too; while Horizon is stopped its queue still fills up.
    config()->set('horizon.defaults', ['supervisor-extra' => ['connection' => 'redis', 'queue' => ['reports'], 'maxProcesses' => 1]]);
    QueuePayloads::push(LiveState::redis($this->app), 'reports', LiveState::job('App\\Jobs\\Export', LiveState::NOW - 4));
    QueuePayloads::push(LiveState::redis($this->app), 'reports', LiveState::job('App\\Jobs\\Export', LiveState::NOW - 2));

    $live = $this->getJson('/horizon/observability/live?var-supervisor=supervisor-extra')->assertOk()->json();

    expect($live['horizon']['status'])->toBe(-1)
        ->and($live['scope']['queues'])->toBe(['reports'])
        ->and($live['pending']['value'])->toBe(2)
        ->and($live['errors'])->toBe([]);
});

it('does not scope a configured supervisor that Horizon would not start (maxProcesses 0)', function (): void {
    config()->set('horizon.environments.testing.supervisor-slow.maxProcesses', 0);
    QueuePayloads::push(LiveState::redis($this->app), 'slow', LiveState::job('App\\Jobs\\Export', LiveState::NOW - 4));

    $live = $this->getJson('/horizon/observability/live?var-supervisor=supervisor-slow')->assertOk()->json();

    // The exporter does not export `slow` either (Topology skips the supervisor), so 0 is what the off mode shows.
    expect($live['scope']['queues'])->toBe([])
        ->and($live['pending']['value'])->toBe(0);
});

it('serves a cached copy of a scope with a queue name that is not valid UTF-8, identical to the first answer', function (): void {
    LiveState::horizon($this->app);
    config()->set('horizon.environments.testing.supervisor-default.queue', ["caf\xE9"]);
    QueuePayloads::push(LiveState::redis($this->app), "caf\xE9", LiveState::job('App\\Jobs\\SendInvoice', LiveState::NOW - 3));
    $prefix = TestCase::redisPrefix().'horizon:';

    $first = $this->getJson('/horizon/observability/live')->assertOk()->json();
    $keys = RedisKeys::client()->keys($prefix.(new Keys)->liveSnapshot('*'));
    $second = $this->getJson('/horizon/observability/live')->assertOk()->json();

    expect($keys)->toHaveCount(1)
        ->and($first['pending']['caption'])->toBe("Oldest: 3 s \u{00B7} caf\u{FFFD}")
        ->and($second)->toBe($first);
});
