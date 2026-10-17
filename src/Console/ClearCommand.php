<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use Illuminate\Console\Command;

/**
 * PUBLIC `horizon-observability:clear` (Arch Q7).
 *
 * Stub (T02): deletes nothing yet. Owned by T08 (WP6).
 */
final class ClearCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'horizon-observability:clear';

    /**
     * @var string
     */
    protected $description = 'Delete the cached job classes (runs with php artisan optimize:clear)';

    public function handle(): int
    {
        $this->components->info('Job class discovery is not implemented yet; nothing to clear.');

        return self::SUCCESS;
    }
}
