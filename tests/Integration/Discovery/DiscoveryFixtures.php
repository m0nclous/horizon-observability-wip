<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Integration\Discovery;

use Workbench\App\Discovery\Jobs\ImportChunk;
use Workbench\App\Discovery\Jobs\ProcessPodcast;
use Workbench\App\Discovery\Jobs\PruneUploads;
use Workbench\App\Discovery\Listeners\SendPodcastNotification;
use Workbench\App\Discovery\Mail\WelcomeMail;
use Workbench\App\Discovery\Notifications\InvoicePaid;

/**
 * The workbench discovery fixtures (`workbench/app/Discovery`, Arch 8.2) and what discovery must find in them.
 */
final class DiscoveryFixtures
{
    /**
     * The queueable classes, sorted: a plain job, a job queued through its abstract parent, an invokable job, a queued
     * listener, a queued mailable and a queued notification. Everything else there is skipped.
     */
    public const FOUND = [
        ImportChunk::class,
        ProcessPodcast::class,
        PruneUploads::class,
        SendPodcastNotification::class,
        WelcomeMail::class,
        InvoicePaid::class,
    ];

    public static function directory(): string
    {
        return dirname(__DIR__, 3).'/workbench/app/Discovery';
    }
}
