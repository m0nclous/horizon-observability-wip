<?php

declare(strict_types=1);

/*
| Probe helper (tests/Truth/probes/replica-clocks): one exporter replica of a checkout's workbench application whose
| clock is `<skew>` seconds off the real time (Laravel's and CarbonImmutable's test clocks, moved with the real time),
| scraped in-process once a second and written to a file that the probe's load balancer hands to Prometheus.
| Usage: php export-loop.php <checkout> <file> <seconds> <skew>
*/

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DF\HorizonObservability\Metrics\Exporter;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application;
use Orchestra\Testbench\Foundation\Config;

$checkout = $argv[1] ?? '';
$file = $argv[2] ?? '';
$seconds = (int) ($argv[3] ?? 0);
$skew = (float) ($argv[4] ?? 0);
require $checkout.'/vendor/autoload.php';

$app = Application::createFromConfig(Config::loadFromYaml($checkout));
$deadline = microtime(true) + $seconds;

while (microtime(true) < $deadline) {
    $now = CarbonImmutable::createFromTimestamp(microtime(true) + $skew);
    Carbon::setTestNow($now);
    CarbonImmutable::setTestNow($now);
    $text = $app->make(Exporter::class)->scrape(Request::create('/horizon/observability/metrics'));
    file_put_contents($file.'.tmp', $text);
    rename($file.'.tmp', $file);
    usleep(1_000_000);
}
