<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;

/**
 * Event subscriber that records the attempts of `RedisJob`s that passed `JobProcessing` (Arch Q6.2, C6, C20).
 *
 * The provider subscribes it when `collectors.attempts` is on, which resolves it at boot: its constructor must not
 * touch Redis or the DB. Every listener catches everything; nothing reaches the worker.
 *
 * Stub (T02): the listeners are no-ops. Owned by T04 (WP2).
 *
 * @internal
 */
final class AttemptTracker
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            JobProcessing::class => 'onProcessing',
            JobExceptionOccurred::class => 'onExceptionOccurred',
            JobAttempted::class => 'onAttempted',
            JobTimedOut::class => 'onTimedOut',
        ];
    }

    public function onProcessing(JobProcessing $event): void {}

    public function onExceptionOccurred(JobExceptionOccurred $event): void {}

    public function onAttempted(JobAttempted $event): void {}

    public function onTimedOut(JobTimedOut $event): void {}
}
