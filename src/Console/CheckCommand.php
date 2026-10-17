<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use Illuminate\Console\Command;

/**
 * PUBLIC `horizon-observability:check` (Arch Q7).
 *
 * Stub (T02): checks nothing yet. Owned by T14 (WP10).
 */
final class CheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'horizon-observability:check
                            {--json : Print the report as JSON}
                            {--strict : Exit 1 on warnings too}
                            {--live-push-test : Push one test frame to Grafana Live}';

    /**
     * @var string
     */
    protected $description = 'Check the configuration, access, routes, Redis and collectors; exits 1 on errors';

    public function handle(): int
    {
        $this->components->warn('horizon-observability:check is not implemented yet.');

        return self::SUCCESS;
    }
}
