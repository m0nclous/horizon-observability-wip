<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Queues\PayloadClock;
use DF\HorizonObservability\Queues\QueueInspector;
use DF\HorizonObservability\Tests\Support\QueuePayloads;

/*
| Adversarial probes: a fix changed PayloadClock::isFirstAttempt() from `(int) $attempts === 0` to
| `abs((float) $attempts) < 1`, so it no longer casts a float past the integer range. queue_state.lua mirrors the old
| rule ("attempts that is absent, not numeric or truncates to 0 is a first attempt") and treats a value that is not
| finite as a first attempt. Both sides must keep agreeing for attempts outside the integer and the double range too,
| or the wait of a queue (Lua) and the wait samples of its attempts (PHP) read the same payload differently.
*/

pest()->group('redis');

dataset('attempts outside the integer range', [
    '2^64 as a JSON number' => ['{"displayName":"App\\\\Jobs\\\\A","attempts":18446744073709551616,"pushedAt":"1700000020.5"}'],
    '-1e30 as a JSON number' => ['{"displayName":"App\\\\Jobs\\\\A","attempts":-1e30,"pushedAt":"1700000021.5"}'],
    '"1e30" as a numeric string' => ['{"displayName":"App\\\\Jobs\\\\A","attempts":"1e30","pushedAt":"1700000022.5"}'],
    '1e999 as a JSON number (INF after json_decode)' => ['{"displayName":"App\\\\Jobs\\\\A","attempts":1e999,"pushedAt":"1700000023.5"}'],
    '-1e999 as a JSON number (-INF after json_decode)' => ['{"displayName":"App\\\\Jobs\\\\A","attempts":-1e999,"pushedAt":"1700000024.5"}'],
    '"1e999" as a numeric string (INF after the float cast)' => ['{"displayName":"App\\\\Jobs\\\\A","attempts":"1e999","pushedAt":"1700000025.5"}'],
]);

it('reads attempts outside the integer and the double range like PayloadClock does', function (string $payload): void {
    QueuePayloads::push(app('redis')->connection('default'), 'default', $payload);
    $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    $now = CarbonImmutable::createFromTimestamp(1700100000);
    $inspector = app(QueueInspector::class);
    $expected = PayloadClock::isFirstAttempt($decoded) ? PayloadClock::readyAt($decoded) : null;

    expect($inspector->inspect('redis', 'default', $now)->firstFreshReadyAt)->toBe($expected)
        ->and($inspector->live('redis', 'default', $now, true)->firstFreshReadyAt)->toBe($expected);
})->with('attempts outside the integer range');
