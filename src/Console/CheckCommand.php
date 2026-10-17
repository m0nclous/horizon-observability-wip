<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\CheckStatus;
use DF\HorizonObservability\Support\Diagnostics;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * PUBLIC `horizon-observability:check` (Arch Q7, Addendum Q9' WP10): explains every problem with one actionable fix
 * line (owner decision 12) and gates deploys with its exit code.
 *
 * - Exit 1 on an error; with `--strict` also on a warning; 0 otherwise.
 * - `--json` prints `{"status", "errors", "warnings", "checks": [{section, name, status, message, fix}]}` and nothing
 *   else.
 * - `--live-push-test` pushes one `tiles` frame to the Grafana Live stream `<id>_check` of every configured Grafana
 *   and reports the HTTP status with the hint for 401 and 403 (Addendum R4.10). The dashboard's own stream is never
 *   touched.
 *
 * Tokens are never printed, only whether they are set.
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

    public function handle(Diagnostics $diagnostics): int
    {
        $results = $diagnostics->check($this->option('live-push-test') === true);
        $errors = count(array_filter($results, static fn (CheckResult $result): bool => $result->status === CheckStatus::Error));
        $warnings = count(array_filter($results, static fn (CheckResult $result): bool => $result->status === CheckStatus::Warning));
        $failed = $errors > 0 || ($warnings > 0 && $this->option('strict') === true);

        if ($this->option('json') === true) {
            $this->output->writeln((string) json_encode([
                'status' => Diagnostics::worst($results)->value,
                'errors' => $errors,
                'warnings' => $warnings,
                'checks' => array_map(static fn (CheckResult $result): array => $result->toArray(), $results),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->report($results);

        $summary = sprintf('%d %s, %d %s.', $errors, $errors === 1 ? 'error' : 'errors', $warnings, $warnings === 1 ? 'warning' : 'warnings');

        match (true) {
            $errors > 0 => $this->components->error($summary.' Fix the errors above.'),
            $warnings > 0 => $failed
                ? $this->components->error($summary.' --strict fails on warnings.')
                : $this->components->warn($summary),
            default => $this->components->info('Everything is fine.'),
        };

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<CheckResult>  $results
     */
    private function report(array $results): void
    {
        $section = null;

        foreach ($results as $result) {
            if ($result->section !== $section) {
                $section = $result->section;
                $this->newLine();
                $this->line('  <options=bold>'.$section.'</>');
            }

            $color = match ($result->status) {
                CheckStatus::Ok => 'green',
                CheckStatus::Info => 'gray',
                CheckStatus::Warning => 'yellow',
                CheckStatus::Error => 'red',
            };
            $label = match ($result->status) {
                CheckStatus::Warning => ' <fg=yellow;options=bold>WARN</>',
                CheckStatus::Error => ' <fg=red;options=bold>ERROR</>',
                default => '',
            };

            $this->components->twoColumnDetail($this->escape($result->name), '<fg='.$color.'>'.$this->escape($result->message).'</>'.$label);

            if ($result->fix !== null) {
                $prefix = $result->status->severity() > 0 ? '<fg=yellow>Fix:</>' : '<fg=gray>Tip:</>';
                $this->line('  '.$prefix.' '.$this->escape($result->fix));
            }
        }

        $this->newLine();
    }

    private function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
