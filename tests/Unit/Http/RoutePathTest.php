<?php

declare(strict_types=1);

use DF\HorizonObservability\Http\RoutePath;

it('derives the base from horizon.path', function (mixed $horizonPath, string $expected): void {
    config()->set('horizon.path', $horizonPath);
    config()->set('horizon-observability.path');

    expect(RoutePath::base(app('config')))->toBe($expected)
        ->and(RoutePath::apiPath(app('config')))->toBe('/'.$expected);
})->with([
    'default' => ['horizon', 'horizon/observability'],
    'nested' => ['admin/horizon', 'admin/horizon/observability'],
    'slashes' => ['/admin/horizon/', 'admin/horizon/observability'],
    'root of its own domain' => ['', 'horizon/observability'],
    'slash' => ['/', 'horizon/observability'],
    'not a string' => [null, 'horizon/observability'],
]);

it('lets the path key override the base', function (): void {
    config()->set('horizon-observability.path', '/metrics/horizon/');

    expect(RoutePath::base(app('config')))->toBe('metrics/horizon')
        ->and(RoutePath::apiPath(app('config')))->toBe('/metrics/horizon');
});

it('ignores an unusable path key', function (mixed $path): void {
    config()->set('horizon.path', 'horizon');
    config()->set('horizon-observability.path', $path);

    expect(RoutePath::base(app('config')))->toBe('horizon/observability');
})->with(['metrics/{x}', 'a/../b', 'a b', '', '/', 42]);

it('normalises custom paths', function (): void {
    expect(RoutePath::normalize('/x/y/'))->toBe('x/y')
        ->and(RoutePath::normalize("x\ty"))->toBeNull()
        ->and(RoutePath::normalize(null))->toBeNull();
});
