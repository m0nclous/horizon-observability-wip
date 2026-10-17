<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\JobClassLabel;
use DF\HorizonObservability\Queues\PayloadClock;
use DF\HorizonObservability\Queues\QueueInspector;
use DF\HorizonObservability\Tests\Support\QueuePayloads;

/*
| Readiness parity (Arch Q6.3, C8, C19): queue_state.lua extracts pushedAt, createdAt, delay, attempts and
| displayName from the raw payload string, Queues\PayloadClock reads the decoded payload. Every shared fixture
| (tests/Fixtures/payloads.php) and every extra queue fixture (tests/Fixtures/queues/parity.php) must give the same
| first-attempt readiness and the same job class on both sides, in full and in live mode.
*/

pest()->group('redis');

/**
 * @return array<string, array{payload: string, ready_at: float|null, first_attempt: bool, display_name: string}>
 */
function readinessParityFixtures(): array
{
    $fixtures = __DIR__.'/../Fixtures';

    return [...require $fixtures.'/payloads.php', ...require $fixtures.'/queues/parity.php'];
}

dataset('readiness parity payloads', array_map(static fn (array $case): array => [$case['payload'], $case['ready_at'], $case['first_attempt'], $case['display_name']], readinessParityFixtures()));

it('reads the same readiness and class as PayloadClock', function (string $payload, ?float $readyAt, bool $firstAttempt, string $displayName): void {
    QueuePayloads::push(app('redis')->connection('default'), 'default', $payload);
    $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    $expected = PayloadClock::isFirstAttempt($decoded) ? PayloadClock::readyAt($decoded) : null;
    $now = CarbonImmutable::createFromTimestamp(1700100000);
    $inspector = app(QueueInspector::class);

    $full = $inspector->inspect('redis', 'default', $now);
    $live = $inspector->live('redis', 'default', $now, true);

    // The fixture's own expectation holds on the PHP side, and the script agrees with it exactly.
    expect(PayloadClock::readyAt($decoded))->toBe($readyAt)
        ->and(PayloadClock::isFirstAttempt($decoded))->toBe($firstAttempt)
        ->and($full->firstFreshReadyAt)->toBe($expected)
        ->and($live->firstFreshReadyAt)->toBe($expected)
        ->and($full->scanned)->toBe(1)
        ->and($full->pendingByClass)->toBe([JobClassLabel::normalize($displayName) => 1])
        ->and($full->pendingByClass)->toBe([JobClassLabel::normalize(is_string($decoded['displayName'] ?? null) ? $decoded['displayName'] : null) => 1])
        ->and($live->pendingByClass)->toBe([]);
})->with('readiness parity payloads');

it('reads the same class from a reserved payload', function (string $payload, ?float $readyAt, bool $firstAttempt, string $displayName): void {
    QueuePayloads::reserve(app('redis')->connection('default'), 'default', $payload, 1700100090.0);

    $snapshot = app(QueueInspector::class)->inspect('redis', 'default', CarbonImmutable::createFromTimestamp(1700100000));

    expect($snapshot->reservedByClass)->toBe([JobClassLabel::normalize($displayName) => 1])
        ->and($snapshot->reserved)->toBe(1);
})->with('readiness parity payloads');
