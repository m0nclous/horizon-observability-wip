<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Workbench\App\Discovery\Events\PodcastPublished;

/**
 * Discovery fixture: a queued listener. Found.
 */
final class SendPodcastNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PodcastPublished $event): void {}
}
