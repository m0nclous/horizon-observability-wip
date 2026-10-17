<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use Illuminate\Console\Command;

/**
 * PUBLIC `horizon-observability:flush` (Arch Q7).
 *
 * Stub (T02): deletes nothing yet. Owned by T14 (WP10).
 */
final class FlushCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'horizon-observability:flush
                            {--force : Run without confirmation, also in production}';

    /**
     * @var string
     */
    protected $description = 'Delete every package storage key (counters, histograms, links, live data)';

    public function handle(): int
    {
        $this->components->warn('horizon-observability:flush is not implemented yet; nothing was deleted.');

        return self::SUCCESS;
    }
}
