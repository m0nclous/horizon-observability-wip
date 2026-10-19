<?php

declare(strict_types=1);

/*
| Probe helper (tests/Truth/probes/first-value): dispatch N CompletingJob fixtures to the default Redis queue of the
| workbench application in the checkout given as the first argument. Usage: php dispatch.php <checkout> <count>
*/

use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\CompletingJob;
use Orchestra\Testbench\Foundation\Application;
use Orchestra\Testbench\Foundation\Config;

$checkout = $argv[1] ?? '';
$count = (int) ($argv[2] ?? 0);
require $checkout.'/vendor/autoload.php';

Application::createFromConfig(Config::loadFromYaml($checkout));

for ($index = 0; $index < $count; $index++) {
    CompletingJob::dispatch()->onConnection('redis')->onQueue('default');
}

echo "dispatched {$count}\n";
