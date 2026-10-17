<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\Tests\Integration\Discovery\DiscoveryFixtures;
use DF\HorizonObservability\Tests\Integration\Discovery\TemporaryClasses;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\OptimizeClearCommand;
use Illuminate\Foundation\Console\OptimizeCommand;
use Illuminate\Support\ServiceProvider;

/*
| horizon-observability:cache and :clear (Arch Q7, Q6.9, C22): the deploy-time discovery cache in
| bootstrap/cache/horizon-observability.php, and the optimize / optimize:clear hook, which exists iff Laravel >= 11.27.1
| (ServiceProvider::optimizes()). The bootstrap path points at a temporary directory, so nothing is written into the
| testbench skeleton.
*/

beforeEach(function (): void {
    $this->bootstrap = sys_get_temp_dir().'/ho-discovery-command-'.getmypid().'-'.bin2hex(random_bytes(3));
    mkdir($this->bootstrap.'/cache', 0755, true);
    $this->app->useBootstrapPath($this->bootstrap);
    $this->cacheFile = $this->bootstrap.'/cache/horizon-observability.php';

    config()->set('horizon-observability.job_class_paths', [DiscoveryFixtures::directory()]);
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(JobClassCache::class);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->bootstrap);
});

/**
 * The optimize (or optimize:clear) task keys other than the package's, to skip them with --except.
 *
 * @param  class-string  $command
 */
function otherOptimizeTasks(string $command, string $method): string
{
    /** @var array<string, string> $tasks */
    $tasks = (new ReflectionMethod($command, $method))->invoke(new $command);

    return implode(',', array_filter(array_keys($tasks), static fn (string $key): bool => $key !== 'horizon-observability'));
}

it('caches the discovered job classes into bootstrap/cache (C22)', function (): void {
    $this->artisan('horizon-observability:cache')
        ->expectsOutputToContain('Job classes cached successfully (6 classes).')
        ->assertExitCode(0);

    $data = require $this->cacheFile;

    expect($data['version'])->toBe(JobClassCache::VERSION)
        ->and($data['generated_at'])->toBeInt()
        ->and($data['classes'])->toBe(DiscoveryFixtures::FOUND)
        ->and(app(JobClassCache::class)->classes())->toBe(DiscoveryFixtures::FOUND);
});

it('rewrites the cache file on every run', function (): void {
    $classes = TemporaryClasses::create();

    try {
        config()->set('horizon-observability.job_class_paths', [$classes->directory()]);
        $this->app->forgetInstance(Config::class);
        $first = $classes->job('First');

        $this->artisan('horizon-observability:cache')->expectsOutputToContain('(1 class)')->assertExitCode(0);
        $second = $classes->job('Second');
        $this->artisan('horizon-observability:cache')->expectsOutputToContain('(2 classes)')->assertExitCode(0);

        expect((require $this->cacheFile)['classes'])->toBe([$first, $second]);
    } finally {
        $classes->remove();
    }
});

it('fails with exit code 1 when the file cannot be written', function (): void {
    touch($this->bootstrap.'/cache/blocked');
    $this->app->useBootstrapPath($this->bootstrap.'/cache/blocked');

    $this->artisan('horizon-observability:cache')
        ->expectsOutputToContain('The job classes could not be cached: RuntimeException: Cannot create the directory')
        ->assertExitCode(1);
});

it('clears the cache file, also when there is none', function (): void {
    $this->artisan('horizon-observability:cache')->assertExitCode(0);

    expect(is_file($this->cacheFile))->toBeTrue();

    $this->artisan('horizon-observability:clear')
        ->expectsOutputToContain('Job class cache cleared successfully.')
        ->assertExitCode(0);

    expect(is_file($this->cacheFile))->toBeFalse();

    $this->artisan('horizon-observability:clear')->assertExitCode(0);
});

it('hooks into optimize and optimize:clear iff Laravel >= 11.27.1 (C22)', function (): void {
    $supported = version_compare(Application::VERSION, '11.27.1', '>=');

    expect(method_exists(ServiceProvider::class, 'optimizes'))->toBe($supported);

    if (! $supported) {
        expect(property_exists(ServiceProvider::class, 'optimizeCommands'))->toBeFalse();

        return;
    }

    expect(ServiceProvider::$optimizeCommands['horizon-observability'] ?? null)->toBe('horizon-observability:cache')
        ->and(ServiceProvider::$optimizeClearCommands['horizon-observability'] ?? null)->toBe('horizon-observability:clear');
});

it('runs with php artisan optimize and optimize:clear', function (): void {
    if (! method_exists(ServiceProvider::class, 'optimizes') || ! (new OptimizeCommand)->getDefinition()->hasOption('except')) {
        $this->markTestSkipped('optimize --except needs a newer Laravel; the hook itself is asserted above.');
    }

    $this->artisan('optimize', ['--except' => otherOptimizeTasks(OptimizeCommand::class, 'getOptimizeTasks')])->assertExitCode(0);

    expect((require $this->cacheFile)['classes'])->toBe(DiscoveryFixtures::FOUND);

    $this->artisan('optimize:clear', ['--except' => otherOptimizeTasks(OptimizeClearCommand::class, 'getOptimizeClearTasks')])->assertExitCode(0);

    expect(is_file($this->cacheFile))->toBeFalse();
});
