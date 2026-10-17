<?php

declare(strict_types=1);

use DF\HorizonObservability\Http\JobListController;
use DF\HorizonObservability\Http\Middleware\Authorize;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Support\LogRecorder;

/*
| When the Redis behind a list cannot be read, the job list answers 503 JSON and logs one throttled line, instead of
| an exception (and its report) on every Grafana refresh. Read paths do not use the circuit breaker (Arch Q6.6).
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->withoutMiddleware(Authorize::class);
    $this->app->instance('log', $this->log = new LogRecorder);
});

function unavailableTestBreak(string $connection): void
{
    config()->set('database.redis.'.$connection.'.port', 1);
    app('redis')->purge($connection);
    app()->forgetInstance(RedisClients::class);
}

it('answers 503 JSON when Horizon\'s Redis is down, and logs once', function (string $list): void {
    unavailableTestBreak('horizon');

    $this->get('/horizon/observability/jobs/'.$list)
        ->assertStatus(503)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['message' => JobListController::UNAVAILABLE]);
    $this->get('/horizon/observability/jobs/'.$list)->assertStatus(503);

    expect($this->log->messages('warning'))->toHaveCount(1)
        ->and($this->log->messages('warning')[0])->toStartWith('horizon-observability: jobs.'.$list.' failed:');
})->with(['pending', 'completed', 'failed']);

it('answers 503 JSON when a queue\'s Redis is down', function (): void {
    unavailableTestBreak('default');

    $this->get('/horizon/observability/jobs/delayed')
        ->assertStatus(503)
        ->assertExactJson(['message' => JobListController::UNAVAILABLE]);

    expect($this->log->messages('warning')[0] ?? '')->toStartWith('horizon-observability: jobs.delayed failed:');
});

it('answers 503 JSON when the running supervisors cannot be read', function (): void {
    unavailableTestBreak('horizon');

    $this->get('/horizon/observability/jobs/delayed?var-supervisor=supervisor-default')->assertStatus(503);
    $this->get('/horizon/observability/jobs/delayed')->assertOk();
});
