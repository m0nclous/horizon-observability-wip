<?php

declare(strict_types=1);

use DF\HorizonObservability\Support\Versions;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * @param  array<string, string>  $installed  package => normalised version
 */
function versions(string $laravel, array $installed, array $pretty = []): Versions
{
    return new Versions(
        app('config'),
        $laravel,
        static fn (string $package): ?string => $installed[$package] ?? null,
        static fn (string $package): ?string => $pretty[$package] ?? null,
    );
}

it('detects the payload delay (Laravel >= 12.11 and Horizon >= 5.49)', function (string $laravel, ?string $horizon, bool $expected): void {
    expect(versions($laravel, $horizon === null ? [] : ['laravel/horizon' => $horizon])->payloadCarriesDelay())->toBe($expected);
})->with([
    'modern' => ['13.37.0', '5.50.1.0', true],
    'floors' => ['12.11.0', '5.49.0.0', true],
    'Laravel 12.10' => ['12.10.9', '5.50.1.0', false],
    'Laravel 11' => ['11.23.0', '5.50.1.0', false],
    'Horizon 5.48' => ['13.0.0', '5.48.9.0', false],
    'dev branch is legacy' => ['13.0.0', 'dev-master', false],
    'unknown Horizon is legacy' => ['13.0.0', null, false],
]);

it('computes the payload delay mode once', function (): void {
    $calls = 0;
    $versions = new Versions(app('config'), '13.0.0', function () use (&$calls): string {
        $calls++;

        return '5.50.1.0';
    });

    $versions->payloadCarriesDelay();
    $versions->payloadCarriesDelay();

    expect($calls)->toBe(1);
});

it('reports the installed versions without a leading v', function (): void {
    $versions = versions('13.37.0', [], ['laravel/horizon' => 'v5.50.1', Versions::PACKAGE => 'dev-main', 'predis/predis' => 'v3.7.0']);

    expect($versions->laravel())->toBe('13.37.0')
        ->and($versions->horizon())->toBe('5.50.1')
        ->and($versions->package())->toBe('dev-main')
        ->and($versions->php())->toBe(PHP_VERSION)
        ->and(versions('13.0.0', [])->horizon())->toBeNull();
});

it('names the Redis client with its version', function (): void {
    config()->set('database.redis.client', 'predis');
    expect(versions('13.0.0', [], ['predis/predis' => 'v3.7.0'])->redisClient())->toBe('predis 3.7.0')
        ->and(versions('13.0.0', [])->redisClient())->toBe('predis');

    config()->set('database.redis.client', 'phpredis');
    expect(versions('13.0.0', [])->redisClient())->toBe(extension_loaded('redis') ? 'phpredis '.phpversion('redis') : 'phpredis');

    config()->set('database.redis.client', 'custom');
    expect(versions('13.0.0', [])->redisClient())->toBe('custom');
});

it('uses the real installed versions by default', function (): void {
    $versions = new Versions(app('config'));

    expect($versions->laravel())->toBe(Application::VERSION)
        ->and($versions->horizon())->toMatch('/^\d+\.\d+\.\d+/')
        ->and($versions->supportsOptimize())->toBe(method_exists(ServiceProvider::class, 'optimizes'))
        ->and($versions->supportsOptimize())->toBe(version_compare(Application::VERSION, '11.27.1', '>='));
});
