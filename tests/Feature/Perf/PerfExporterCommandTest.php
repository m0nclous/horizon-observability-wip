<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Workbench\App\Perf\SyntheticModel;

/*
| workbench:perf-exporter (ADDENDUM R2.2 step 2, T11): a real server process on a free port serves the model on
| /metrics without job or instance labels.
*/

it('serves the synthetic metrics to Prometheus', function (): void {
    $process = new Process([PHP_BINARY, 'vendor/bin/testbench', 'workbench:perf-exporter', '--host=127.0.0.1', '--port=0', '--max-requests=2', '--schema=legacy'], dirname(__DIR__, 3));
    $process->setTimeout(30);
    $process->start();
    $url = null;

    $process->waitUntil(static function (string $type, string $output) use (&$url): bool {
        if (preg_match('#Listening on (http://\S+)/metrics#', $output, $match) === 1) {
            $url = $match[1];
        }

        return $url !== null;
    });

    // A raw request: the server must speak plain HTTP/1.1 (and PHP 8.5 deprecates $http_response_header).
    $get = static function (string $path) use ($url): array {
        $client = stream_socket_client('tcp://'.substr((string) $url, strlen('http://')), $errorCode, $errorMessage, 10);

        if ($client === false) {
            throw new RuntimeException(sprintf('Cannot connect to %s: %s', $url, $errorMessage));
        }

        stream_set_timeout($client, 10);
        fwrite($client, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $response = (string) stream_get_contents($client);
        fclose($client);
        [$head, $body] = explode("\r\n\r\n", $response, 2) + ['', ''];

        return [explode("\r\n", $head), $body];
    };
    [$metricsHeaders, $metrics] = $get('/metrics');
    [$missingHeaders, $missing] = $get('/missing');
    $process->wait();

    expect($metricsHeaders[0])->toBe('HTTP/1.1 200 OK')
        ->and($metricsHeaders)->toContain('Content-Type: text/plain; version=0.0.4; charset=utf-8')
        ->and($metricsHeaders)->toContain('Content-Length: '.strlen($metrics))
        ->and($metrics)->toStartWith('office_horizon_status{} ')
        ->and(substr_count($metrics, "\n"))->toBeGreaterThan(5000)
        ->and($metrics)->not->toContain('job="')
        ->and($missingHeaders[0])->toBe('HTTP/1.1 404 Not Found')
        ->and($missing)->toContain('/metrics')
        ->and($process->getExitCode())->toBe(0)
        ->and((new SyntheticModel('medium', SyntheticModel::LEGACY))->prefix())->toBe('office_');
});
