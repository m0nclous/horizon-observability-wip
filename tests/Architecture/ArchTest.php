<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Http\RoutePath;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Finder\Finder;

/*
| Architecture rules (Arch 8.4). After WP8B (T13) a rule "no office in src/ or resources/" joins them.
*/

/**
 * @param  list<string>  $directories  relative to the repository root; missing ones are skipped
 * @return array<string, string> relative path => contents
 */
function repositoryFiles(array $directories, string $pattern): array
{
    $root = dirname(__DIR__, 2);
    $existing = array_values(array_filter(array_map(static fn (string $directory): string => $root.'/'.$directory, $directories), is_dir(...)));
    $files = [];

    if ($existing === []) {
        return [];
    }

    foreach (Finder::create()->files()->in($existing)->name($pattern) as $file) {
        $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getContents();
    }

    ksort($files);

    return $files;
}

arch('source files declare strict types')
    ->expect('DF\HorizonObservability')
    ->toUseStrictTypes();

it('declares strict types in every PHP file', function (): void {
    $missing = array_keys(array_filter(
        repositoryFiles(['src', 'config', 'routes', 'tests', 'workbench'], '*.php'),
        static fn (string $contents): bool => preg_match('/^<\?php\s+declare\(strict_types=1\);/', $contents) !== 1,
    ));

    expect($missing)->toBe([]);
});

arch('no debugging statements')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('src never uses Workbench, Tests or App')
    ->expect('DF\HorizonObservability')
    ->not->toUse(['Workbench', 'DF\HorizonObservability\Tests', 'App'])
    ->ignoring('DF\HorizonObservability\Tests');

arch('all package classes are final')
    ->expect('DF\HorizonObservability')
    ->classes()
    ->toBeFinal()
    ->ignoring('DF\HorizonObservability\Tests');

arch('all workbench classes are final')
    ->expect('Workbench\App')
    ->classes()
    ->toBeFinal();

arch('config() only in Config, the provider, RoutePath and Horizon')
    ->expect(['config', Illuminate\Support\Facades\Config::class])
    ->toOnlyBeUsedIn([Config::class, HorizonObservabilityServiceProvider::class, RoutePath::class, 'DF\HorizonObservability\Horizon', 'DF\HorizonObservability\Tests']);

it('calls env() only in config/', function (): void {
    $calls = array_keys(array_filter(
        repositoryFiles(['src', 'routes', 'workbench/app'], '*.php'),
        static fn (string $contents): bool => preg_match('/(?<![\w>$:\\\\])env\(/', $contents) === 1,
    ));

    expect($calls)->toBe([]);
});

arch('the Redis facade is never used in src')
    ->expect(Redis::class)
    ->not->toBeUsedIn('DF\HorizonObservability')
    ->ignoring('DF\HorizonObservability\Tests');

arch('collectors do not depend on Http')
    ->expect('DF\HorizonObservability\Collectors')
    ->not->toUse('DF\HorizonObservability\Http');

it('ascii only', function (): void {
    $files = repositoryFiles(['src', 'config', 'routes', 'resources/lua', 'resources/views'], '/\.(php|lua)$/');
    $nonAscii = array_keys(array_filter($files, static fn (string $contents): bool => preg_match('/[^\x00-\x7F]/', $contents) === 1));

    expect($files)->not->toBe([])
        ->and($nonAscii)->toBe([]);
});
