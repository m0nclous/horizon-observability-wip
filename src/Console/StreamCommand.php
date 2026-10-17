<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use Illuminate\Console\Command;

/**
 * PUBLIC `horizon-observability:stream` (Arch Q7, Addendum R4.6).
 *
 * Stub (T02): exits 1. Owned by T12 (WP-L2).
 */
final class StreamCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'horizon-observability:stream
                            {--once : Compute and push one snapshot, then exit}
                            {--max-time=3600 : Exit after N seconds; the process manager restarts it}
                            {--memory=128 : Exit when memory exceeds N MB}';

    /**
     * @var string
     */
    protected $description = 'Push the live overview to Grafana Live every live.stream.interval seconds';

    public function handle(): int
    {
        $this->components->error('horizon-observability:stream is not implemented yet.');

        return self::FAILURE;
    }
}
