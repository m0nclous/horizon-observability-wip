<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Render;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

/**
 * PUBLIC `horizon-observability:grafana`: renders the Grafana dashboard and the alert rules into
 * `{path}/dashboards/horizon-observability.json` and `{path}/alerting/horizon-observability.json`, with
 * `--recording-rules` also the opt-in Prometheus recording rules into `{path}/prometheus/horizon-observability.rules.yml`,
 * or with `--check` compares those files with the render and exits 1 when they differ (for the CI of an
 * infrastructure repository).
 *
 * Metric names carry the prefix of `horizon-observability.namespace` (default `ho_`), also with `--defaults`, so the
 * render matches what the application exports. The Live Overview polls the application by default (`--live=poll`), or
 * reads Prometheus (`--live=off`) while `horizon-observability.live.enabled` is false; `--live=stream` subscribes to
 * the Grafana Live stream of `horizon-observability.live.stream.id` (the package default with `--defaults`), and
 * `--live=off` reads every tile from Prometheus.
 *
 * A relative path is relative to the application root (`base_path()`), wherever the command runs from;
 * under `vendor/bin/testbench` that is its skeleton, so the package's own scripts pass an absolute path.
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
        {path=grafana : Output directory, relative to the application root}
        {--defaults : Render with the package defaults, ignoring the application config (the metric prefix still follows horizon-observability.namespace)}
        {--check : Render in memory and exit 1 when the files differ from the render}
        {--uid=horizon-observability : Dashboard uid ([A-Za-z0-9_-], at most 29 characters); the alert rule uids start with it}
        {--title=Queues : Dashboard title}
        {--job= : Prometheus job (scrape job of the package endpoint) the dashboard selects by default and the alert rules are scoped to; empty = all jobs}
        {--scrape-interval=10s : Scrape interval S of the package endpoint, 5s to 60s: the panels\' min interval, the busy window max(15s, 2 x S), the counter lookback max(10m, 10 x S) and the refresh}
        {--folder=Horizon : Grafana folder of the alert rules}
        {--datasource-uid= : Uid of the Prometheus datasource of the alert rules (the description is set in the constructor)}
        {--live= : Live Overview mode: poll (the live tiles read the live endpoint of the application at every dashboard refresh), stream (they read Grafana Live, pushed every second by horizon-observability:stream) or off (every tile reads Prometheus); default poll, or off while horizon-observability.live.enabled is false}
        {--live-poll-interval=5s : Poll mode: the dashboard refresh, 1s to 5m; Grafana raises it to its min_refresh_interval, 5s by default}
        {--live-stream-id= : Stream mode: the Grafana Live stream id, as horizon-observability.live.stream.id of the stream command (default: that config value, or the package default with --defaults)}
        {--health-rules : Add the opt-in health rules: a failing metrics collector, and more than one scraped target per job}
        {--recording-rules : Also render the opt-in Prometheus recording rules (attempts and durations per queue) into prometheus/horizon-observability.rules.yml; the dashboard does not read them}
        {--prometheus-datasource= : Name regex of the Prometheus datasource (default: Grafana\'s default datasource)}
        {--infinity-datasource= : Name regex of the Infinity datasource of the job lists (default: the first one)}';

    /**
     * @var string
     */
    protected $description = 'Render the Grafana dashboard and alert rules';

    public function __construct()
    {
        parent::__construct();

        // The signature parser ends an option at its first "}", so this description cannot be written there
        $options = $this->getDefinition()->getOptions();
        $options['datasource-uid'] = new InputOption(
            'datasource-uid',
            null,
            InputOption::VALUE_OPTIONAL,
            'Uid of the Prometheus datasource of the alert rules (default: '.DashboardOptions::DATASOURCE_PLACEHOLDER
                .', interpolated by Grafana at provisioning)',
        );
        $this->getDefinition()->setOptions(array_values($options));
    }

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

        $this->components->info(sprintf(
            'Rendered the Grafana dashboard and alert rules%s into %s.',
            isset($render[Render::RECORDING_RULES]) ? ', and the Prometheus recording rules,' : '',
            $directory,
        ));

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

        $live = $this->liveMode($config);

        $streamId = $this->text('live-stream-id', required: false);

        if ($streamId === '') {
            $streamId = $this->option('defaults') === true ? Config::DEFAULT_LIVE_STREAM_ID : $config->liveStreamId;
        } elseif (preg_match(DashboardOptions::STREAM_ID_PATTERN, $streamId) !== 1) {
            throw new InvalidArgumentException('--live-stream-id must be 1 to 64 characters of A-Z, a-z, 0-9, "_" and "-" (as horizon-observability.live.stream.id).');
        }

        $job = $this->text('job', required: false);
        $scrapeInterval = $this->scrapeInterval();
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
            scrapeInterval: $scrapeInterval,
            alertFolder: $folder,
            alertDatasourceUid: $datasource === '' ? DashboardOptions::DATASOURCE_PLACEHOLDER : $datasource,
            live: $live,
            job: $job === '' ? null : $job,
            healthRules: $this->option('health-rules') === true,
            livePollInterval: $this->livePollInterval(),
            liveStreamId: $streamId,
            recordingRules: $this->option('recording-rules') === true,
        );
    }

    /**
     * `--live`: poll by default, but off while the application serves no live endpoint
     * (`horizon-observability.live.enabled` false, without `--defaults`): the live tiles would read a route that
     * does not exist. An explicit poll or stream render of such an application is kept, with a warning.
     *
     * @throws InvalidArgumentException
     */
    private function liveMode(Config $config): LiveMode
    {
        $disabled = $this->option('defaults') !== true && ! $config->liveEnabled;

        if ($this->option('live') === null) {
            if ($disabled) {
                $this->components->warn('horizon-observability.live.enabled is false: rendering --live=off. Enable it, or pass --live=poll or --live=stream, for the Live Overview.');
            }

            return $disabled ? LiveMode::Off : LiveMode::Poll;
        }

        $live = LiveMode::tryFrom($this->text('live'));

        if ($live === null) {
            throw new InvalidArgumentException('--live must be off, poll or stream.');
        }

        if ($disabled && $live->isLive()) {
            $this->components->warn(sprintf(
                'horizon-observability.live.enabled is false, so this application serves no live endpoint: the --live=%s tiles stay without live values until it is enabled.',
                $live->value,
            ));
        }

        return $live;
    }

    /**
     * `--live-poll-interval` in seconds: `<n>s` or `<n>m`, 1 second to 5 minutes.
     *
     * @throws InvalidArgumentException
     */
    private function livePollInterval(): int
    {
        $seconds = $this->seconds('live-poll-interval');

        if ($seconds === null || $seconds < DashboardOptions::MIN_LIVE_POLL_INTERVAL || $seconds > DashboardOptions::MAX_LIVE_POLL_INTERVAL) {
            throw new InvalidArgumentException(sprintf(
                '--live-poll-interval must be a duration from %ds to %dm such as 5s or 1m (the dashboard refresh in poll mode).',
                DashboardOptions::MIN_LIVE_POLL_INTERVAL,
                intdiv(DashboardOptions::MAX_LIVE_POLL_INTERVAL, 60),
            ));
        }

        return $seconds;
    }

    /**
     * A duration option `<n>s` or `<n>m` in seconds, or null when it is not one.
     */
    private function seconds(string $option): ?int
    {
        $value = $this->option($option);

        if (! is_string($value) || preg_match('/^(\d{1,6})(s|m)$/', trim($value), $match) !== 1) {
            return null;
        }

        return (int) $match[1] * ($match[2] === 'm' ? 60 : 1);
    }

    /**
     * `--scrape-interval` in seconds: `<n>s` or `1m`, within the supported 5 to 60 seconds.
     *
     * @throws InvalidArgumentException
     */
    private function scrapeInterval(): int
    {
        $seconds = $this->seconds('scrape-interval');

        if ($seconds === null || $seconds < DashboardOptions::MIN_SCRAPE_INTERVAL || $seconds > DashboardOptions::MAX_SCRAPE_INTERVAL) {
            throw new InvalidArgumentException(sprintf(
                '--scrape-interval must be a duration from %ds to %ds such as 10s, 30s or 1m (the scrape interval of the package endpoint).',
                DashboardOptions::MIN_SCRAPE_INTERVAL,
                DashboardOptions::MAX_SCRAPE_INTERVAL,
            ));
        }

        return $seconds;
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

    /**
     * The output directory: an absolute path as is, a relative one under the application root.
     */
    private function directory(): string
    {
        $path = $this->argument('path');
        $path = is_string($path) && $path !== '' ? $path : 'grafana';

        if (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = $this->laravel->basePath($path);
        }

        return rtrim($path, '/\\');
    }
}
