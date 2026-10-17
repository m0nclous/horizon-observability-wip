<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use Illuminate\Console\Command;

/**
 * PUBLIC `horizon-observability:install` (Arch Q7).
 *
 * Stub (T02): does nothing yet. Owned by T14 (WP10).
 */
final class InstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'horizon-observability:install
                            {--token= : Use this token instead of generating one}
                            {--no-env : Do not write the token to .env and .env.example}
                            {--force : Overwrite the published config without asking}';

    /**
     * @var string
     */
    protected $description = 'Publish the config, create a token and print the Prometheus and Grafana setup';

    public function handle(): int
    {
        $this->components->warn('horizon-observability:install is not implemented yet.');

        return self::SUCCESS;
    }
}
