<?php

declare(strict_types=1);

/*
| Probe helper (tests/Truth/probes/first-value): the real exporter of a checkout's workbench application, scraped
| in-process once a second and written to a file that a static HTTP server hands to Prometheus.
| Usage: php export-loop.php <checkout> <file> <seconds>
*/

use DF\HorizonObservability\Metrics\Exporter;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;
use Orchestra\Testbench\Foundation\Config;

$checkout = $argv[1] ?? '';
$file = $argv[2] ?? '';
$seconds = (int) ($argv[3] ?? 0);
require $checkout.'/vendor/autoload.php';

$app = Application::createFromConfig(Config::loadFromYaml($checkout));
$deadline = microtime(true) + $seconds;

while (microtime(true) < $deadline) {
    $text = $app->make(Exporter::class)->scrape(Request::create('/horizon/observability/metrics'));
    file_put_contents($file.'.tmp', $text);
    rename($file.'.tmp', $file);
    usleep(1_000_000);
}
