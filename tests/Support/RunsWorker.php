<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * Runs `queue:work --stop-when-empty` in the test process, so queue events reach the package's listeners.
 */
trait RunsWorker
{
    /**
     * Work the queue until it is empty (or one job with `$once`); the command's exit code. The timeout defaults to 0
     * (no SIGALRM handler in the test process). The memory limit defaults to 4096 MB: `queue:work` stops with exit
     * code 12 (`Worker::EXIT_MEMORY_LIMIT`) before the first job once the test process uses more than its default
     * 128 MB, which a long suite does.
     *
     * @param  array<string, mixed>  $options  extra `queue:work` options, e.g. `['--tries' => 3]`
     */
    protected function runWorker(string $queue = 'default', string $connection = 'redis', bool $once = false, array $options = []): int
    {
        return Artisan::call('queue:work', [
            'connection' => $connection,
            '--queue' => $queue,
            '--stop-when-empty' => true,
            '--once' => $once,
            '--sleep' => 0,
            '--tries' => 1,
            '--timeout' => 0,
            '--memory' => 4096,
            ...$options,
        ]);
    }
}
