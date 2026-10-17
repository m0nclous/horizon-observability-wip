<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use Illuminate\Console\Command;

/**
 * PUBLIC `horizon-observability:cache` (Arch Q7).
 *
 * Stub (T02): writes no cache yet. Owned by T08 (WP6).
 */
final class CacheCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'horizon-observability:cache';

    /**
     * @var string
     */
    protected $description = 'Cache the discovered job classes (runs with php artisan optimize)';

    public function handle(): int
    {
        $this->components->info('Job class discovery is not implemented yet; nothing to cache.');

        return self::SUCCESS;
    }
}
