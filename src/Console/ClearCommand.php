<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * PUBLIC `horizon-observability:clear`: deletes the job class cache file and the runtime cache
 * entry, so the next scrape discovers the classes again. `php artisan optimize:clear` runs it on Laravel >= 11.27.1.
 * Metric data is never deleted here (that is `horizon-observability:flush`).
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

    public function handle(JobClassCache $cache): int
    {
        try {
            $cache->clear();
        } catch (Throwable $e) {
            $this->components->error('The job class cache could not be cleared: '.FailureReporter::describe($e));

            return self::FAILURE;
        }

        $this->components->info('Job class cache cleared successfully.');

        return self::SUCCESS;
    }
}
