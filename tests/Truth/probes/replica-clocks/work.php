<?php

declare(strict_types=1);

/*
| Probe helper (tests/Truth/probes/replica-clocks): dispatch N CompletingJob fixtures to the default Redis queue of a
| checkout's workbench application and work them with a worker whose clock is `<skew>` seconds off the real time.
| Usage: php work.php <checkout> <count> <skew>
*/

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\CompletingJob;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Foundation\Application;
use Orchestra\Testbench\Foundation\Config;

$checkout = $argv[1] ?? '';
$count = (int) ($argv[2] ?? 0);
$skew = (float) ($argv[3] ?? 0);
require $checkout.'/vendor/autoload.php';

Application::createFromConfig(Config::loadFromYaml($checkout));

for ($index = 0; $index < $count; $index++) {
    CompletingJob::dispatch()->onConnection('redis')->onQueue('default');
}

$now = CarbonImmutable::createFromTimestamp(microtime(true) + $skew);
Carbon::setTestNow($now);
CarbonImmutable::setTestNow($now);
$code = Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'default', '--stop-when-empty' => true, '--sleep' => 0]);

echo "worked {$count} with the worker clock {$skew} s off: exit {$code}\n";
