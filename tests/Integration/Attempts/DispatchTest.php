<?php

declare(strict_types=1);

use DF\HorizonObservability\Attempts\LegacyReadyAt;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Events;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\CompletingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;

/*
| Dispatch paths of the legacy ready_at listener (A01): the pilot cast JobQueued::$delay to int outside its try, so
| ->delay(now()->addMinute()) threw "could not be converted to int" into the code that dispatched the job (after the
| push, possibly rolling back the surrounding transaction).
*/

pest()->group('redis');

beforeEach(function (): void {
    // On a legacy stack (Laravel < 12.11 or Horizon < 5.49) the provider has already registered the listener; a second
    // registration would write every key twice.
    if (! in_array([LegacyReadyAt::class, 'onQueued'], Event::getRawListeners()[JobQueued::class] ?? [], true)) {
        Event::listen(JobQueued::class, [LegacyReadyAt::class, 'onQueued']);
    }

    $this->start = now()->startOfSecond()->toImmutable();
    $this->travelTo($this->start);
    $this->readyAt = static function (): array {
        $values = [];

        foreach (Storage::packageKeys() as $key) {
            if (str_contains($key, ':ready_at:')) {
                $short = substr($key, (int) strpos($key, (new Keys)->root()));
                $values[] = [(int) Storage::store()->reader()->get($short), Storage::ttl($short)];
            }
        }

        return $values;
    };
});

it('carbon delay does not throw (A01)', function (): void {
    CompletingJob::dispatch()->onConnection('redis')->delay(now()->addMinute());

    expect(($this->readyAt)())->toBe([[$this->start->getTimestamp() + 60, 60 + LegacyReadyAt::EXTRA_TTL_SECONDS]]);
});

it('normalises DateInterval and integer delays like the queue does', function (): void {
    CompletingJob::dispatch()->onConnection('redis')->delay(new DateInterval('PT90S'));
    CompletingJob::dispatch()->onConnection('redis')->delay(30);

    $values = ($this->readyAt)();
    sort($values);

    expect($values)->toBe([
        [$this->start->getTimestamp() + 30, 30 + LegacyReadyAt::EXTRA_TTL_SECONDS],
        [$this->start->getTimestamp() + 90, 90 + LegacyReadyAt::EXTRA_TTL_SECONDS],
    ]);
});

it('writes one key per delayed dispatch through the private storage client', function (): void {
    $commands = RedisCommands::start($this->app);

    CompletingJob::dispatch()->onConnection('redis')->delay(15);

    expect($commands->names(RedisClients::STORAGE))->toBe(['SETEX']);
});

it('ignores a delay it cannot read', function (mixed $delay): void {
    $this->app->instance('log', $log = new LogRecorder);

    app(LegacyReadyAt::class)->onQueued(Events::queued('redis', 'job-1', $delay));

    expect(($this->readyAt)())->toBe([])
        ->and($log->records)->toBe([]);
})->with([
    'object' => [new stdClass],
    'array' => [[1, 2]],
    'text' => ['soon'],
]);

it('never throws into the dispatcher and logs a storage failure once', function (): void {
    $this->app->instance('log', $log = new LogRecorder);
    $this->app->bind(Keys::class, static fn () => throw new RuntimeException('Keys are broken.'));

    CompletingJob::dispatch()->onConnection('redis')->delay(now()->addMinute());
    CompletingJob::dispatch()->onConnection('redis')->delay(now()->addMinutes(2));

    expect($log->messages('warning'))->toBe(['horizon-observability: ready_at failed: RuntimeException: Keys are broken.']);
});
