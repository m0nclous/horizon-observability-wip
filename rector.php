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
        // Public method signatures are frozen contracts (Arch Appendix C, ADDENDUM Appendix C2): stubs keep their
        // parameters and no-op listeners until the owning task implements them.
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
    // The floor is Laravel 11.23 (Arch 8.6): upgrade rules up to Laravel 11, nothing newer.
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_110,
    ]);
