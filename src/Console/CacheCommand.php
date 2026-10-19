<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * PUBLIC `horizon-observability:cache`: discovers the queueable job classes and writes
 * `bootstrap/cache/horizon-observability.php`, which the `job_classes` collector reads instead of scanning the code.
 * `php artisan optimize` runs it on Laravel >= 11.27.1; earlier versions add it to the deploy script.
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

    public function handle(JobClassCache $cache): int
    {
        try {
            $count = $cache->write();
        } catch (Throwable $e) {
            $this->components->error('The job classes could not be cached: '.FailureReporter::describe($e));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Job classes cached successfully (%d %s).', $count, $count === 1 ? 'class' : 'classes'));

        return self::SUCCESS;
    }
}
