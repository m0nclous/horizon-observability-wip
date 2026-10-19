<?php

declare(strict_types=1);

/*
| Workbench Horizon topology, merged over Horizon's defaults: three supervisors in local and testing.
| supervisor-pool runs balance=false, so its pooled queue label is "mail,sms".
*/

$environment = [
    'supervisor-default' => [
        'connection' => 'redis',
        'queue' => ['default'],
        'balance' => 'auto',
        'minProcesses' => 1,
        'maxProcesses' => 3,
        'tries' => 1,
        'timeout' => 60,
    ],
    'supervisor-slow' => [
        'connection' => 'redis',
        'queue' => ['slow', 'reports'],
        'balance' => 'auto',
        'minProcesses' => 1,
        'maxProcesses' => 2,
        'tries' => 1,
        'timeout' => 300,
    ],
    'supervisor-pool' => [
        'connection' => 'redis',
        'queue' => ['mail', 'sms'],
        'balance' => false,
        'minProcesses' => 1,
        'maxProcesses' => 2,
        'tries' => 1,
        'timeout' => 60,
    ],
];

return [
    'defaults' => [],

    'environments' => [
        'local' => $environment,
        'testing' => $environment,
    ],

    'waits' => [
        'redis:default' => 10,
        'redis:slow' => 30,
    ],
];
