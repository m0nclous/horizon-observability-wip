<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Http\Middleware\Authorize;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\JobStore\Support\HorizonJobs;
use DF\HorizonObservability\Tests\Support\RedisCommands;

/*
| The pilot returned up to 1000 bytes of every failed job's first exception line, with SQL text,
| values and internal hostnames, and had no way to turn it off. The `exceptions` mode now chooses: full (short
| class and message, first line), class (short class only) or none (the text never leaves Redis).
*/

pest()->group('redis');

const EXCEPTION_MODE_TEST_STORED = "Illuminate\\Database\\QueryException: SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, Host: db-primary.internal, SQL: select * from users where email = 'a@example.com') in /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php:825\nStack trace:\n#0 /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(779): Illuminate\\Database\\Connection->runQueryCallback()\n#1 {main}";

beforeEach(function (): void {
    $this->withoutMiddleware(Authorize::class);
    HorizonJobs::using($this->app)->failed('App\Jobs\SyncUsers', 'default', 1700000001.0, 1700000002.0, 2, EXCEPTION_MODE_TEST_STORED, 'f-1');
});

function exceptionModeTestUse(mixed $mode): void
{
    config()->set('horizon-observability.exceptions', $mode);
    app()->forgetInstance(Config::class);
}

it('shows the short class and message of the first line in full mode (the default)', function (): void {
    expect(config('horizon-observability.exceptions'))->toBe('full')
        ->and($this->get('/horizon/observability/jobs/failed')->assertOk()->json('data.0.exception'))
        ->toBe("QueryException: SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, Host: db-primary.internal, SQL: select * from users where email = 'a@example.com')");
});

it('shows the short class only in class mode', function (): void {
    exceptionModeTestUse('class');
    $response = $this->get('/horizon/observability/jobs/failed')->assertOk();

    expect($response->json('data.0.exception'))->toBe('QueryException')
        ->and($response->getContent())->not->toContain('SQLSTATE')->not->toContain('db-primary');
});

it('shows nothing in class mode when the stored line does not start with a class name', function (): void {
    // An exception with its own __toString() is stored as whatever it returns.
    HorizonJobs::using($this->app)->failed('App\Jobs\SyncUsers', 'default', 1700000003.0, 1700000004.0, 1, "Connection to db-primary.internal:5432 refused for user billing\nStack trace:", 'f-2');
    exceptionModeTestUse('class');
    $response = $this->get('/horizon/observability/jobs/failed')->assertOk();

    expect($response->json('data.*.exception'))->toBe([null, 'QueryException'])
        ->and($response->getContent())->not->toContain('db-primary')->not->toContain('billing');
});

it('shows no exception in none mode and never reads it from Redis', function (): void {
    exceptionModeTestUse('none');
    $commands = RedisCommands::start($this->app);
    $response = $this->get('/horizon/observability/jobs/failed')->assertOk();
    $replies = array_filter($commands->commands(RedisClients::READER.'horizon'), static fn (array $command): bool => $command['command'] === 'EVAL');

    expect($response->json('data.0'))->toMatchArray(['id' => 'f-1', 'attempts' => 2, 'exception' => null])
        ->and($response->getContent())->not->toContain('QueryException')->not->toContain('SQLSTATE')
        ->and($replies)->toHaveCount(1)
        ->and(json_encode(array_values($replies)[0]['parameters']))->toContain('"0"');
});

it('falls back to full mode for an invalid value', function (): void {
    exceptionModeTestUse('verbose');

    expect(app(Config::class)->hasProblem('exceptions'))->toBeTrue()
        ->and($this->get('/horizon/observability/jobs/failed')->json('data.0.exception'))->toStartWith('QueryException: SQLSTATE[HY000]');
});

it('reads the mode per request, case-insensitively', function (string $mode, ?string $expected): void {
    exceptionModeTestUse($mode);

    expect($this->get('/horizon/observability/jobs/failed')->json('data.0.exception'))->toBe($expected);
})->with([
    'CLASS' => ['CLASS', 'QueryException'],
    ' none ' => [' none ', null],
]);
