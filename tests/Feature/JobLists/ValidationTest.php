<?php

declare(strict_types=1);

use DF\HorizonObservability\Http\Middleware\Authorize;
use DF\HorizonObservability\Tests\Support\RedisCommands;

/*
| Invalid job-list input (a limit above the 1000 cap included) answers 422 JSON before any Redis command, whatever
| the client accepts, because ForceJson runs first.
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->withoutMiddleware(Authorize::class);
});

it('answers 422 JSON for invalid input without touching Redis', function (string $query, string $field): void {
    $commands = RedisCommands::start($this->app);

    $this->get('/horizon/observability/jobs/pending?'.$query, ['Accept' => 'text/html'])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonValidationErrors([$field]);

    expect($commands->count())->toBe(0);
})->with([
    'limit 0' => ['limit=0', 'limit'],
    'limit above the cap' => ['limit=1001', 'limit'],
    'limit not a number' => ['limit=abc', 'limit'],
    'limit a fraction' => ['limit=1.5', 'limit'],
    'negative from' => ['from=-5', 'from'],
    'to not a number' => ['to=now', 'to'],
    'from after to' => ['from=1700000001000&to=1700000000000', 'from'],
    'a filter value that is not UTF-8' => ['var-queue=%FF', 'var-queue'],
]);

it('accepts the limits of the range and ignores unknown variables', function (string $query): void {
    $this->get('/horizon/observability/jobs/failed?'.$query)->assertOk()->assertExactJson(['data' => []]);
})->with([
    'limit 1' => ['limit=1'],
    'limit 1000' => ['limit=1000'],
    'equal from and to' => ['from=1700000000000&to=1700000000000'],
    'an unknown variable' => ['var-host=web-1&var-unknown=%FF'],
    'a dashboard without filters' => ['limit=500&from=0'],
]);

it('validates the period of the delayed list, which it does not apply', function (): void {
    $this->get('/horizon/observability/jobs/delayed?from=1700000001000&to=1700000000000')
        ->assertUnprocessable();

    $this->get('/horizon/observability/jobs/delayed?from=1700000000000&to=1700000001000')
        ->assertOk();
});
