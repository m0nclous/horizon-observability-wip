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

    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $metrics = (string) file_get_contents($url.'/metrics', false, $context);
    $metricsHeaders = $http_response_header;
    $missing = (string) file_get_contents($url.'/missing', false, $context);
    $missingHeaders = $http_response_header;
    $process->wait();

    expect($metricsHeaders[0])->toBe('HTTP/1.1 200 OK')
        ->and($metricsHeaders)->toContain('Content-Type: text/plain; version=0.0.4; charset=utf-8')
        ->and($metrics)->toStartWith('office_horizon_status{} ')
        ->and(substr_count($metrics, "\n"))->toBeGreaterThan(5000)
        ->and($metrics)->not->toContain('job="')
        ->and($missingHeaders[0])->toBe('HTTP/1.1 404 Not Found')
        ->and($missing)->toContain('/metrics')
        ->and($process->getExitCode())->toBe(0)
        ->and((new SyntheticModel('medium', SyntheticModel::LEGACY))->prefix())->toBe('office_');
});
