<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Workbench\App\Perf\SyntheticModel;

/**
 * Serves the synthetic perf-profile metrics on GET /metrics (ADDENDUM R2.2 step 2), continuing the backfilled history
 * at scrape time, so dashboard ranges ending at "now" are continuous. A minimal single-threaded HTTP server: one
 * Prometheus scrapes it.
 *
 * The exposition has no `job` or `instance` labels: Prometheus adds them, and exported ones would be renamed
 * `exported_*` and duplicate every series.
 */
final class PerfExporterCommand extends Command
{
    protected $signature = 'workbench:perf-exporter
        {--host=0.0.0.0 : Address to listen on}
        {--port=8391 : Port to listen on; 0 picks a free port}
        {--profile=medium : medium or large; must match the backfill}
        {--schema=catalog : catalog or legacy; must match the backfill}
        {--namespace= : Metric prefix without "_"; must match the backfill}
        {--max-requests=0 : Stop after this many requests; 0 = until Ctrl+C or SIGTERM}';

    protected $description = 'Serve the synthetic perf-profile metrics for Prometheus (development only)';

    public function handle(): int
    {
        $namespace = $this->option('namespace');

        try {
            $model = new SyntheticModel(
                $this->text('profile', 'medium'),
                $this->text('schema', SyntheticModel::CATALOG),
                is_string($namespace) && $namespace !== '' ? $namespace : null,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $address = sprintf('tcp://%s:%d', $this->text('host', '0.0.0.0'), $this->number('port', 8391));
        $server = @stream_socket_server($address, $errorCode, $errorMessage);

        if ($server === false) {
            $this->error(sprintf('Cannot listen on %s: %s', $address, $errorMessage));

            return self::FAILURE;
        }

        $running = true;
        $this->trap([SIGINT, SIGTERM], static function () use (&$running): void {
            $running = false;
        });

        $name = (string) stream_socket_get_name($server, false);
        $this->info(sprintf('Listening on http://%s/metrics (%s profile, %s schema, prefix "%s")', $name, $model->profile, $model->schema, $model->prefix()));
        $limit = $this->number('max-requests', 0);
        $served = 0;

        while ($running && ($limit === 0 || $served < $limit)) {
            $read = [$server];
            $write = $except = [];

            if (@stream_select($read, $write, $except, 1) < 1) {
                continue;
            }

            $client = @stream_socket_accept($server, 1);

            if ($client === false) {
                continue;
            }

            $served++;
            $this->respond($client, $model);
        }

        fclose($server);

        return self::SUCCESS;
    }

    /**
     * @param  resource  $client
     */
    private function respond($client, SyntheticModel $model): void
    {
        stream_set_timeout($client, 5);
        $requestLine = (string) fgets($client, 8192);

        while (($line = fgets($client, 8192)) !== false && trim($line) !== '') {
            // Headers are ignored.
        }

        $path = explode(' ', $requestLine)[1] ?? '';
        $path = (string) strtok($path, '?');

        [$status, $type, $body] = $path === '/metrics'
            ? ['200 OK', 'text/plain; version=0.0.4; charset=utf-8', $model->exposition(microtime(true))]
            : ['404 Not Found', 'text/plain; charset=utf-8', "Not found. Prometheus scrapes /metrics.\n"];

        fwrite($client, sprintf("HTTP/1.1 %s\r\nContent-Type: %s\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s", $status, $type, strlen($body), $body));
        fclose($client);
    }

    private function text(string $name, string $default): string
    {
        $value = $this->option($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    private function number(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : $default;
    }
}
