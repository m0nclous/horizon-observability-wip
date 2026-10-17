<?php

declare(strict_types=1);

arch('source files declare strict types')
    ->expect('DF\HorizonObservability')
    ->toUseStrictTypes();

arch('no debugging statements')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();
