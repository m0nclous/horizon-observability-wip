<?php

declare(strict_types=1);

/*
| The router of the dashboard truth stack (design section 6.5): the Infinity datasource of both Grafanas calls the
| real package routes ({base}/live, {base}/jobs/{list}) of the workbench application on the active Redis world,
| with Carbon pinned at the dataset's end V (Server/PinnedApp).
|
|   DASHBOARD_TRUTH_RUN_DIR=build/dashboard-truth/<run> PHP_CLI_SERVER_WORKERS=4 \
|   php -S 127.0.0.1:$APP_PORT tests/DashboardTruth/Server/router.php
|
| bin/run starts and stops it.
*/

use DF\HorizonObservability\Tests\DashboardTruth\Server\PinnedApp;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$runDir = getenv('DASHBOARD_TRUTH_RUN_DIR');

if (! is_string($runDir) || $runDir === '') {
    http_response_code(503);
    echo 'DASHBOARD_TRUTH_RUN_DIR is not set.';

    return;
}

PinnedApp::serve($runDir, $root);
