<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Discovery fixture (Arch 8.2): an event marked as queued but without handle(); it is never pushed itself. Skipped.
 */
final readonly class PodcastPublished implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public string $podcast) {}
}
