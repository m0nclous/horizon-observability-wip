<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Live\Stream\LiveStreamer;
use Illuminate\Console\Command;

/**
 * PUBLIC `horizon-observability:stream` (Arch Q7, Addendum R4.6): pushes the live overview to Grafana Live every
 * `live.stream.interval` seconds under a Redis leader lease. Run it as a long-lived process (supervisord, systemd, a
 * Kubernetes Deployment) on any number of replicas; one of them pushes.
 *
 * - Preconditions: `live.enabled`, `live.stream.grafana_url` and `live.stream.token`; otherwise it exits 1 naming the
 *   env var to set. The token is never printed.
 * - SIGTERM and SIGINT (with pcntl) finish the tick, release the lease and exit 0; without pcntl the lease expires
 *   after 5 s.
 * - `--once` pushes one snapshot and exits 0, or 1 when no push succeeded (another process leads, Grafana refused).
 * - `--max-time` and `--memory` end the process with 0 for the process manager to restart it.
 */
final class StreamCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'horizon-observability:stream
                            {--once : Compute and push one snapshot, then exit}
                            {--max-time=3600 : Exit after N seconds; the process manager restarts it (0 = never)}
                            {--memory=128 : Exit when memory exceeds N MB}';

    /**
     * @var string
     */
    protected $description = 'Push the live overview to Grafana Live every live.stream.interval seconds';

    public function handle(Config $config, LiveStreamer $streamer): int
    {
        if (! $config->liveEnabled) {
            $this->components->error('The live overview is disabled: set HORIZON_OBSERVABILITY_LIVE_ENABLED=true (live.enabled).');

            return self::FAILURE;
        }

        if ($config->liveGrafanaUrls === []) {
            $this->components->error($config->hasProblem('live.stream.grafana_url')
                ? 'HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL has no valid http(s) URL (live.stream.grafana_url).'
                : 'Set HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL to the Grafana base URL reachable from the app (live.stream.grafana_url).');

            return self::FAILURE;
        }

        if ($config->liveToken === null) {
            $this->components->error('Set HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN to a Grafana service account token with role Editor (live.stream.token).');

            return self::FAILURE;
        }

        $maxTime = $this->integerOption('max-time', 0);
        $memory = $this->integerOption('memory', 1);

        if ($maxTime === null || $memory === null) {
            return self::FAILURE;
        }

        $stop = false;

        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], static function () use (&$stop): void {
                $stop = true;
            });
        }

        $streamer->listen(function (string $event, string $message): void {
            in_array($event, ['push-failed', 'follower', 'released', 'unavailable'], true)
                ? $this->components->warn($message)
                : $this->components->info($message);
        });

        $this->components->info(sprintf(
            'Streaming the live overview to %s as stream %s every %s s.',
            implode(', ', $config->liveGrafanaUrls),
            $config->liveStreamId,
            rtrim(rtrim(sprintf('%.2F', $config->liveStreamInterval), '0'), '.'),
        ));

        $code = $streamer->run(static function () use (&$stop): bool {
            return $stop;
        }, $maxTime, $memory, (bool) $this->option('once'));

        if ($this->option('once') && $code !== 0) {
            $this->components->error(match ($streamer->lastOutcome()) {
                LiveStreamer::FOLLOWER => 'No push: another stream process holds the leader lease.',
                LiveStreamer::UNAVAILABLE => 'No push of the snapshot: the storage Redis cannot be reached.',
                default => 'No push succeeded.',
            });
        }

        return $code;
    }

    /**
     * A non-negative integer option at least `$minimum`; null (with an error) otherwise.
     */
    private function integerOption(string $name, int $minimum): ?int
    {
        $value = $this->option($name);
        // A string from the command line, an int from Artisan::call().
        $value = is_int($value) ? (string) $value : $value;

        if (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1 && (int) $value >= $minimum) {
            return (int) $value;
        }

        $this->components->error(sprintf('--%s must be an integer of at least %d.', $name, $minimum));

        return null;
    }
}
