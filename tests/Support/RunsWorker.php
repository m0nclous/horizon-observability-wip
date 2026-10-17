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
     * (no SIGALRM handler in the test process).
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
            ...$options,
        ]);
    }
}
