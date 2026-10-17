<?php

declare(strict_types=1);

use DF\HorizonObservability\Live\Captions;

/**
 * @return array<string, array<string, array{0: list<mixed>, 1: mixed}>>
 */
function captionFixtures(): array
{
    return require dirname(__DIR__, 2).'/Fixtures/live/captions.php';
}

it('formats every caption and level rule of Addendum R4.4', function (string $rule, array $arguments, mixed $expected): void {
    $method = match ($rule) {
        'wait_level' => 'waitLevel',
        'utilization_level' => 'utilizationLevel',
        'horizon_level' => 'horizonLevel',
        default => $rule,
    };

    expect(Captions::{$method}(...$arguments))->toBe($expected);
})->with(function (): Generator {
    foreach (captionFixtures() as $rule => $cases) {
        foreach ($cases as $name => [$arguments, $expected]) {
            yield $rule.': '.$name => [$rule, $arguments, $expected];
        }
    }
});

it('covers every public rule with fixtures', function (): void {
    $methods = array_values(array_filter(
        get_class_methods(Captions::class),
        static fn (string $method): bool => (new ReflectionMethod(Captions::class, $method))->isPublic(),
    ));
    $rules = array_map(static fn (string $rule): string => lcfirst(str_replace('_', '', ucwords($rule, '_'))), array_keys(captionFixtures()));

    sort($methods);
    sort($rules);

    expect($rules)->toBe($methods);
});

it('shares the dashboard thresholds', function (): void {
    expect([Captions::WAIT_WARN, Captions::WAIT_CRIT, Captions::UTIL_WARN, Captions::UTIL_CRIT, Captions::SECONDS_TEXT_LIMIT])
        ->toBe([1.0, 2.0, 70, 90, 120]);
});

it('treats a NaN ratio or utilisation as level 0', function (): void {
    expect(Captions::waitLevel(NAN))->toBe(0)
        ->and(Captions::utilizationLevel(NAN, true))->toBe(0);
});
