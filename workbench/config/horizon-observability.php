<?php

declare(strict_types=1);

use function Orchestra\Testbench\workbench_path;

/*
| Workbench overrides of the package config; everything else comes from the package defaults and the
| testbench.yaml environment.
*/

return [
    'job_class_paths' => [
        workbench_path('app/Discovery'),
        workbench_path('app/Simulation'),
    ],
];
