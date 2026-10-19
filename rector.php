<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use RectorLaravel\Set\LaravelLevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/routes',
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/workbench',
    ])
    ->withSkip([
        __DIR__.'/tests/Fixtures',
        // Public method signatures are contracts (an event subscriber's subscribe($events), the empty handle() of the
        // workbench discovery fixtures): Rector must not drop their unused parameters or empty methods.
        RemoveUnusedPublicMethodParameterRector::class,
        RemoveEmptyClassMethodRector::class,
    ])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    // The floor is Laravel 11.23: upgrade rules up to Laravel 11, nothing newer.
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_110,
    ]);
