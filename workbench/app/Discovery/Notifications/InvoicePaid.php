<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Discovery fixture (Arch 8.2): a queued notification (no handle()). Found.
 */
final class InvoicePaid extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
