<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Render;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * PUBLIC `horizon-observability:grafana` (Arch Q5.1): renders the Grafana dashboard and the alert rules into
 * `{path}/dashboards/horizon-observability.json` and `{path}/alerting/horizon-observability.json`, or with `--check`
 * compares those files with the render and exits 1 when they differ (for the CI of an infrastructure repository).
 *
 * Metric names carry the prefix of `horizon-observability.namespace` (default `ho_`), also with `--defaults`, so the
 * render matches what the application exports. The path is relative to the current directory: the application root for `php artisan`, the package root for
 * `vendor/bin/testbench`, whose base path is its own skeleton.
 */
final class GrafanaCommand extends Command
{
    private const UID_PATTERN = '/^[A-Za-z0-9_-]{1,29}$/';

    /**
     * A literal Grafana uid, or an environment variable Grafana interpolates at provisioning.
     */
    private const DATASOURCE_UID_PATTERN = '/^(?:[A-Za-z0-9_-]{1,40}|\$\{[A-Za-z_][A-Za-z0-9_]*\})$/';

    /**
     * @var string
     */
    protected $signature = 'horizon-observability:grafana
        {path=grafana : Output directory, relative to the current directory}
        {--defaults : Render with the package defaults, ignoring the application config (the metric prefix still follows horizon-observability.namespace)}
        {--check : Render in memory and exit 1 when the files differ from the render}
        {--uid=horizon-observability : Dashboard uid ([A-Za-z0-9_-], at most 29 characters); the alert rule uids start with it}
        {--title=Queues : Dashboard title}
        {--job= : Prometheus job (scrape job of the package endpoint) the dashboard selects by default and the alert rules are scoped to; empty = all jobs}
        {--folder=Horizon : Grafana folder of the alert rules}
        {--datasource-uid= : Uid of the Prometheus datasource of the alert rules (default: ${HORIZON_OBSERVABILITY_PROMETHEUS_UID}, interpolated by Grafana)}
        {--health-rules : Add the opt-in health rules: a failing metrics collector, and more than one scraped target per job}
        {--prometheus-datasource= : Name regex of the Prometheus datasource (default: Grafana\'s default datasource)}
        {--infinity-datasource= : Name regex of the Infinity datasource of the job lists (default: the first one)}';

    /**
     * @var string
     */
    protected $description = 'Render the Grafana dashboard and alert rules';

    public function handle(Filesystem $files, Config $config): int
    {
        try {
            $options = $this->renderOptions($config);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $directory = $this->directory();
        $render = (new Render($options))->files();

        return $this->option('check') === true ? $this->check($files, $directory, $render) : $this->write($files, $directory, $render);
    }

    /**
     * @param  array<string, string>  $render
     */
    private function write(Filesystem $files, string $directory, array $render): int
    {
        foreach ($render as $file => $contents) {
            $path = $directory.'/'.$file;
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, $contents);
            $this->components->twoColumnDetail($file, 'written');
        }

        $this->components->info('Rendered the Grafana dashboard and alert rules into '.$directory.'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $render
     */
    private function check(Filesystem $files, string $directory, array $render): int
    {
        $outdated = 0;

        foreach ($render as $file => $contents) {
            $path = $directory.'/'.$file;
            $difference = $files->isFile($path) ? Render::difference($contents, $files->get($path)) : 'missing';

            if ($difference !== null) {
                $outdated++;
                $this->components->twoColumnDetail($file, '<fg=red>outdated</>');
                $this->line('  '.$difference);
            } else {
                $this->components->twoColumnDetail($file, '<fg=green>up to date</>');
            }
        }

        if ($outdated > 0) {
            $this->components->error(sprintf(
                '%d of %d files in %s differ from the render; run the command without --check to update them.',
                $outdated,
                count($render),
                $directory,
            ));

            return self::FAILURE;
        }

        $this->components->info('The Grafana files in '.$directory.' are up to date.');

        return self::SUCCESS;
    }

    /**
     * @throws InvalidArgumentException for an invalid option
     */
    private function renderOptions(Config $config): DashboardOptions
    {
        $uid = $this->text('uid');

        if (preg_match(self::UID_PATTERN, $uid) !== 1) {
            throw new InvalidArgumentException('--uid must be 1 to 29 characters of A-Z, a-z, 0-9, "_" and "-" (the alert rule uids append up to 11).');
        }

        $folder = $this->text('folder');

        if (str_contains($folder, '$')) {
            throw new InvalidArgumentException('--folder must not contain "$": Grafana would read it as an environment variable.');
        }

        $job = $this->text('job', required: false);
        $datasource = $this->text('datasource-uid', required: false);

        if ($datasource !== '' && preg_match(self::DATASOURCE_UID_PATTERN, $datasource) !== 1) {
            throw new InvalidArgumentException('--datasource-uid must be a Grafana uid (1 to 40 characters of A-Z, a-z, 0-9, "_" and "-") or an environment variable such as ${PROMETHEUS_UID}.');
        }

        return new DashboardOptions(
            metricPrefix: $config->prefix(),
            uid: $uid,
            title: $this->text('title'),
            prometheusDatasourceRegex: $this->text('prometheus-datasource', required: false),
            infinityDatasourceRegex: $this->text('infinity-datasource', required: false),
            alertFolder: $folder,
            alertDatasourceUid: $datasource === '' ? DashboardOptions::DATASOURCE_PLACEHOLDER : $datasource,
            job: $job === '' ? null : $job,
            healthRules: $this->option('health-rules') === true,
        );
    }

    /**
     * A single-line option value; empty only when not $required.
     *
     * @throws InvalidArgumentException
     */
    private function text(string $option, bool $required = true): string
    {
        $value = $this->option($option);
        $value = is_string($value) ? trim($value) : '';

        if ($required && $value === '') {
            throw new InvalidArgumentException(sprintf('--%s must not be empty.', $option));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('--%s must be a single line without control characters.', $option));
        }

        return $value;
    }

    private function directory(): string
    {
        $path = $this->argument('path');
        $path = is_string($path) && $path !== '' ? $path : 'grafana';

        if (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = (getcwd() ?: '.').'/'.$path;
        }

        return rtrim($path, '/\\');
    }
}
