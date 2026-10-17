<?php

declare(strict_types=1);

use DF\HorizonObservability\Queues\QueueSnapshot;

/*
| QueueSnapshot derivations (Arch Q6.3, C8): pending includes due delayed jobs, the oldest age is the earlier of the
| first fresh readiness and the earliest due score, 0 when nothing is ready and null (unknown) otherwise.
*/

function queueSnapshot(int $length = 0, int $reserved = 0, int $delayed = 0, int $due = 0, ?float $earliestDue = null, ?float $fresh = null, array $pending = [], array $reservedByClass = []): QueueSnapshot
{
    return new QueueSnapshot('redis', 'default', $length, $reserved, $delayed, $due, $earliestDue, $fresh, array_sum($pending), $pending, $reservedByClass);
}

it('starts empty', function (): void {
    $snapshot = QueueSnapshot::empty('redis', 'emails');

    expect($snapshot->connection)->toBe('redis')
        ->and($snapshot->queue)->toBe('emails')
        ->and($snapshot->pending())->toBe(0)
        ->and($snapshot->delayedNotDue())->toBe(0)
        ->and($snapshot->oldestAgeSeconds(1000.0))->toBe(0.0)
        ->and($snapshot->unscannedPending())->toBe(0)
        ->and($snapshot->unscannedReserved())->toBe(0);
});

it('counts due delayed jobs as pending and the rest as delayed', function (): void {
    $snapshot = queueSnapshot(length: 4, delayed: 5, due: 2, earliestDue: 990.0);

    expect($snapshot->pending())->toBe(6)
        ->and($snapshot->delayedNotDue())->toBe(3);
});

it('ages the queue from the earlier of the fresh head and the earliest due job (C8)', function (): void {
    expect(queueSnapshot(length: 1, fresh: 970.0)->oldestAgeSeconds(1000.0))->toBe(30.0)
        ->and(queueSnapshot(length: 1, due: 1, earliestDue: 950.0, fresh: 970.0)->oldestAgeSeconds(1000.0))->toBe(50.0)
        ->and(queueSnapshot(due: 1, earliestDue: 990.5)->oldestAgeSeconds(1000.0))->toBe(9.5)
        // Clock skew never gives a negative age.
        ->and(queueSnapshot(length: 1, fresh: 1003.0)->oldestAgeSeconds(1000.0))->toBe(0.0)
        // Jobs are ready but no fresh one was scanned and nothing is due: unknown.
        ->and(queueSnapshot(length: 3)->oldestAgeSeconds(1000.0))->toBeNull()
        // Nothing is ready (only not-yet-due delayed jobs): 0.
        ->and(queueSnapshot(delayed: 2)->oldestAgeSeconds(1000.0))->toBe(0.0);
});

it('counts what the class scan did not reach', function (): void {
    $snapshot = queueSnapshot(length: 10, reserved: 4, pending: ['App\\Jobs\\A' => 3, 'App\\Jobs\\B' => 2], reservedByClass: ['App\\Jobs\\A' => 1]);

    expect($snapshot->unscannedPending())->toBe(5)
        ->and($snapshot->unscannedReserved())->toBe(3)
        ->and(queueSnapshot(length: 1, pending: ['App\\Jobs\\A' => 1])->unscannedPending())->toBe(0);
});
