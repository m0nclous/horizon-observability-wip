<?php

declare(strict_types=1);

use DF\HorizonObservability\Queues\PayloadClock;

/**
 * @return array<string, array{payload: string, ready_at: float|null, first_attempt: bool, display_name: string}>
 */
function payloadFixtures(): array
{
    return require dirname(__DIR__, 2).'/Fixtures/payloads.php';
}

it('computes the readiness of every parity fixture', function (string $payload, ?float $readyAt, bool $firstAttempt, string $displayName): void {
    $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

    expect(PayloadClock::readyAt($decoded))->toBe($readyAt)
        ->and(PayloadClock::isFirstAttempt($decoded))->toBe($firstAttempt)
        ->and($decoded['displayName'])->toBe($displayName);
})->with(array_map(array_values(...), payloadFixtures()));

it('ignores a stale delay on a horizon retry (C19)', function (): void {
    $retry = ['createdAt' => 1700000000, 'delay' => 600, 'pushedAt' => '1700005000.5', 'attempts' => 0, 'retry_of' => 'x'];

    expect(PayloadClock::readyAt($retry))->toBe(1700005000.5)
        ->and(PayloadClock::isFirstAttempt($retry))->toBeTrue();
});

it('takes the later of pushedAt and createdAt + delay', function (): void {
    expect(PayloadClock::readyAt(['pushedAt' => '100.5', 'createdAt' => 100, 'delay' => 30]))->toBe(130.0)
        ->and(PayloadClock::readyAt(['pushedAt' => '200.5', 'createdAt' => 100, 'delay' => 30]))->toBe(200.5)
        ->and(PayloadClock::readyAt(['pushedAt' => '100.5', 'createdAt' => 100, 'delay' => null]))->toBe(100.5)
        ->and(PayloadClock::readyAt(['pushedAt' => '100.5', 'delay' => 30]))->toBe(100.5)
        ->and(PayloadClock::readyAt(['createdAt' => 100]))->toBe(100.0)
        ->and(PayloadClock::readyAt(['pushedAt' => 'soon']))->toBeNull()
        ->and(PayloadClock::readyAt([]))->toBeNull();
});

it('counts a payload as a first attempt when attempts is 0 or missing', function (): void {
    expect(PayloadClock::isFirstAttempt(['attempts' => 0]))->toBeTrue()
        ->and(PayloadClock::isFirstAttempt(['attempts' => '0']))->toBeTrue()
        ->and(PayloadClock::isFirstAttempt([]))->toBeTrue()
        ->and(PayloadClock::isFirstAttempt(['attempts' => 1]))->toBeFalse()
        ->and(PayloadClock::isFirstAttempt(['attempts' => 3]))->toBeFalse();
});

it('reads finite attempts past the integer range as a retry instead of casting them (PHP 8.5, T18)', function (): void {
    // (int) of such a float wraps on PHP 8.2-8.4 (2^64 wraps to 0, a first attempt) and warns on 8.5 (failOnWarning).
    expect(PayloadClock::isFirstAttempt(['attempts' => 1.8446744073709552E19]))->toBeFalse()
        ->and(PayloadClock::isFirstAttempt(['attempts' => 1e30]))->toBeFalse()
        ->and(PayloadClock::isFirstAttempt(['attempts' => -1e30]))->toBeFalse()
        ->and(PayloadClock::isFirstAttempt(['attempts' => '1e30']))->toBeFalse()
        ->and(PayloadClock::isFirstAttempt(['attempts' => 0.5]))->toBeTrue()
        ->and(PayloadClock::isFirstAttempt(['attempts' => ' 0']))->toBeTrue()
        ->and(PayloadClock::isFirstAttempt(['attempts' => 1.0]))->toBeFalse();
});

it('reads attempts that are not finite as a first attempt, like (int) and queue_state.lua (T18 QA round 2)', function (): void {
    // (int) truncates INF and -INF to 0 on PHP 8.2-8.5; first_attempt() in queue_state.lua counts them as a first
    // attempt too, so the queue wait (Lua) and the wait samples of the attempt tracker (PHP) read such a payload alike.
    expect(PayloadClock::isFirstAttempt(json_decode('{"attempts":1e999}', true, 512, JSON_THROW_ON_ERROR)))->toBeTrue()
        ->and(PayloadClock::isFirstAttempt(json_decode('{"attempts":-1e999}', true, 512, JSON_THROW_ON_ERROR)))->toBeTrue()
        ->and(PayloadClock::isFirstAttempt(['attempts' => '1e999']))->toBeTrue()
        ->and(PayloadClock::isFirstAttempt(['attempts' => '-1e999']))->toBeTrue()
        ->and(PayloadClock::isFirstAttempt(['attempts' => INF]))->toBeTrue()
        ->and(PayloadClock::isFirstAttempt(['attempts' => NAN]))->toBeTrue();
});
