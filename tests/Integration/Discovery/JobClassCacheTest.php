<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\Tests\Integration\Discovery\TemporaryClasses;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/*
| The job class cache: the deploy-time file wins, else the runtime cache (1 h, keyed by the scanned directories) serves
| every request, else live discovery runs. A fresh JobClassCache instance stands for a new PHP-FPM request: the pilot
| kept the list in a static property, so every request scanned the code again.
*/

beforeEach(function (): void {
    $this->classes = TemporaryClasses::create();
    $this->bootstrap = $this->classes->root.'/bootstrap';
    mkdir($this->bootstrap.'/cache', 0755, true);
    $this->app->useBootstrapPath($this->bootstrap);

    $this->log = new LogRecorder;
    $this->app->instance('log', $this->log);

    discoverIn([$this->classes->directory()]);
});

afterEach(function (): void {
    $this->classes->remove();
});

/**
 * Scan these directories from now on (a fresh Config snapshot and a fresh cache instance).
 *
 * @param  list<string>  $paths
 */
function discoverIn(array $paths): void
{
    config()->set('horizon-observability.job_class_paths', $paths);
    app()->forgetInstance(Config::class);
    app()->forgetInstance(JobClassCache::class);
}

/**
 * Write a cache file by hand (and drop an opcache copy of an earlier one at the same path).
 */
function writeJobClassCacheFile(string $path, string $contents): void
{
    file_put_contents($path, $contents);

    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($path, true);
    }
}

/**
 * A new instance, as a new request would resolve it.
 */
function freshJobClassCache(): JobClassCache
{
    app()->forgetInstance(JobClassCache::class);

    return app(JobClassCache::class);
}

/**
 * The scan marker key for the scanned directories.
 */
function jobClassScanMarker(string $directory): string
{
    return JobClassCache::key([$directory]).JobClassCache::SCAN_SUFFIX;
}

it('discovers the classes when nothing is cached', function (): void {
    $a = $this->classes->job('Alpha');
    $b = $this->classes->job('Beta');

    expect(freshJobClassCache()->classes())->toBe([$a, $b])
        ->and(Cache::get(JobClassCache::key([$this->classes->directory()])))->toBe([$a, $b])
        ->and(Cache::has(jobClassScanMarker($this->classes->directory())))->toBeFalse();
});

it('does not rerun a discovery that died until the marker expires, and warns once', function (): void {
    $a = $this->classes->job('Alpha');
    // A discovery that started a minute ago and never finished: a class that cannot be loaded ended its process.
    Cache::put(jobClassScanMarker($this->classes->directory()), now()->getTimestamp() - JobClassCache::STALE_SCAN_SECONDS, JobClassCache::TTL_SECONDS);

    expect(freshJobClassCache()->classes())->toBe([])
        ->and(freshJobClassCache()->classes())->toBe([])
        ->and($this->log->messages('warning'))->toHaveCount(1)
        ->and($this->log->messages('warning')[0])->toStartWith('horizon-observability: Job class discovery did not finish')
        ->and($this->log->messages('warning')[0])->toContain('php artisan horizon-observability:cache');

    $this->travel(JobClassCache::TTL_SECONDS + 1)->seconds();

    expect(freshJobClassCache()->classes())->toBe([$a])
        ->and(Cache::has(jobClassScanMarker($this->classes->directory())))->toBeFalse();
});

it('turns a discovery that dies with a fatal error into one failed scrape, not one per scrape', function (string $member, string $fatal, int $fatalBelow): void {
    if ($fatalBelow <= PHP_VERSION_ID) {
        $this->markTestSkipped('Not a fatal error on PHP '.PHP_VERSION.' (see the next test).');
    }

    // Loads (it extends a class) and then fails with an uncatchable fatal error.
    $this->classes->write('Unloadable', "final class Unloadable extends \\ArrayObject implements \\Illuminate\\Contracts\\Queue\\ShouldQueue\n{\n    {$member}\n\n    public function handle(): void {}\n}");
    $root = dirname(__DIR__, 3);
    $script = sprintf(<<<'PHP'
        require %s;
        $loader = new Composer\Autoload\ClassLoader(%s);
        $loader->addPsr4(%s, %s);
        $loader->register();
        $app = Orchestra\Testbench\Foundation\Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file.path', %s);
        $app['config']->set('logging.default', 'stderr');
        $app['config']->set('horizon-observability.job_class_paths', [%s]);
        $app->register(DF\HorizonObservability\HorizonObservabilityServiceProvider::class);
        echo json_encode($app->make(DF\HorizonObservability\Discovery\JobClassCache::class)->classes());
        PHP,
        var_export($root.'/vendor/autoload.php', true),
        var_export($this->classes->root.'/vendor', true),
        var_export($this->classes->namespace.'\\', true),
        var_export($this->classes->directory(), true),
        var_export($this->classes->root.'/file-cache', true),
        var_export($this->classes->directory(), true),
    );
    $scrape = static function () use ($script, $root): Process {
        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script], $root);
        $process->run();

        return $process;
    };

    $first = $scrape();

    expect($first->getExitCode())->toBe(255)
        ->and($first->getErrorOutput())->toContain($fatal);

    $second = $scrape(); // the next scrape: the marker of the dead discovery stops it from loading the class again

    expect($second->getExitCode())->toBe(0, $second->getErrorOutput())
        ->and($second->getOutput())->toBe('[]')
        ->and($second->getErrorOutput())->not->toContain($fatal);
})->with([
    'a method incompatible with its parent' => ['public function count(string $mode): string { return $mode; }', 'must be compatible with ArrayObject::count()', PHP_INT_MAX],
    // PHP 8.5 throws an Error instead.
    'a trait of a dev-only package' => ['use \\Missing\\DevOnlyTrait;', 'Trait "Missing\DevOnlyTrait" not found', 80500],
]);

it('skips a class whose trait is missing where PHP throws an Error for it (PHP 8.5+)', function (): void {
    if (PHP_VERSION_ID < 80500) {
        $this->markTestSkipped('A missing trait is an uncatchable fatal error before PHP 8.5 (see the previous test).');
    }

    $a = $this->classes->job('Alpha');
    $this->classes->write('Unloadable', "final class Unloadable extends \\ArrayObject implements \\Illuminate\\Contracts\\Queue\\ShouldQueue\n{\n    use \\Missing\\DevOnlyTrait;\n\n    public function handle(): void {}\n}");

    expect(freshJobClassCache()->classes())->toBe([$a])
        ->and(Cache::has(jobClassScanMarker($this->classes->directory())))->toBeFalse();
});

it('leaves a discovery that is running to its request without a warning', function (): void {
    $this->classes->job('Alpha');
    Cache::put(jobClassScanMarker($this->classes->directory()), now()->getTimestamp(), JobClassCache::TTL_SECONDS);

    expect(freshJobClassCache()->classes())->toBe([])
        ->and($this->log->messages('warning'))->toBe([]);
});

it('leaves a running discovery to its request without a warning on the redis cache store', function (): void {
    // The Redis store keeps a number unserialised, so the marker another request added reads back as a string.
    config()->set('cache.default', 'redis');
    $this->classes->job('Alpha');
    $marker = jobClassScanMarker($this->classes->directory());
    Cache::add($marker, now()->getTimestamp(), JobClassCache::TTL_SECONDS);

    expect(Cache::get($marker))->toBeString()
        ->and(freshJobClassCache()->classes())->toBe([])
        ->and($this->log->messages('warning'))->toBe([]);

    $this->travel(JobClassCache::STALE_SCAN_SECONDS)->seconds();

    expect(freshJobClassCache()->classes())->toBe([])
        ->and($this->log->messages('warning'))->toHaveCount(1)
        ->and($this->log->messages('warning')[0])->toStartWith('horizon-observability: Job class discovery did not finish');
})->group('redis');

it('scans every time with a store that keeps nothing', function (): void {
    $a = $this->classes->job('Alpha');
    config()->set('cache.stores.none', ['driver' => 'null']);
    config()->set('cache.default', 'none');

    expect(freshJobClassCache()->classes())->toBe([$a]);

    $b = $this->classes->job('Beta');

    expect(freshJobClassCache()->classes())->toBe([$a, $b]);
});

it('drops the scan marker on cache and clear', function (): void {
    $this->classes->job('Alpha');
    $marker = jobClassScanMarker($this->classes->directory());

    Cache::put($marker, 1, JobClassCache::TTL_SECONDS);
    freshJobClassCache()->write();

    expect(Cache::has($marker))->toBeFalse();

    Cache::put($marker, 1, JobClassCache::TTL_SECONDS);
    freshJobClassCache()->clear();

    expect(Cache::has($marker))->toBeFalse();
});

it('serves a fresh request from the runtime cache without scanning again', function (): void {
    $a = $this->classes->job('Alpha');

    expect(freshJobClassCache()->classes())->toBe([$a]);

    $b = $this->classes->job('Beta'); // a scan would find it now

    expect(freshJobClassCache()->classes())->toBe([$a])
        ->and(freshJobClassCache()->classes())->toBe([$a]);

    $this->travel(JobClassCache::TTL_SECONDS + 1)->seconds();

    expect(freshJobClassCache()->classes())->toBe([$a, $b]);
});

it('keys the runtime cache by the scanned directories', function (): void {
    $a = $this->classes->job('Alpha');
    $other = TemporaryClasses::create();

    try {
        $b = $other->job('Beta');

        expect(freshJobClassCache()->classes())->toBe([$a]);

        discoverIn([$other->directory()]);

        expect(freshJobClassCache()->classes())->toBe([$b])
            ->and(JobClassCache::key([$this->classes->directory()]))->not->toBe(JobClassCache::key([$other->directory()]))
            ->and(JobClassCache::key([]))->toStartWith(JobClassCache::KEY_PREFIX);
    } finally {
        $other->remove();
    }
});

it('prefers the cache file and never scans while it exists', function (): void {
    $a = $this->classes->job('Alpha');

    expect(freshJobClassCache()->write())->toBe(1)
        ->and(Cache::has(JobClassCache::key([$this->classes->directory()])))->toBeFalse();

    $this->classes->job('Beta');

    expect(freshJobClassCache()->classes())->toBe([$a])
        ->and(Cache::has(JobClassCache::key([$this->classes->directory()])))->toBeFalse();

    writeJobClassCacheFile($this->bootstrap.'/cache/horizon-observability.php', "<?php\n\nreturn ['version' => 1, 'generated_at' => 1, 'classes' => ['App\\\\Jobs\\\\FromTheFile']];\n");

    expect(freshJobClassCache()->classes())->toBe(['App\\Jobs\\FromTheFile']);
});

it('writes the file atomically with its version, time and classes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00:00', 'UTC'));
    $a = $this->classes->job('Alpha');
    $cache = freshJobClassCache();

    $cache->write();

    expect($cache->path())->toBe($this->bootstrap.'/cache/horizon-observability.php')
        ->and(require $cache->path())->toBe(['version' => 1, 'generated_at' => 1792238400, 'classes' => [$a]])
        ->and(file_get_contents($cache->path()))->toStartWith("<?php\n\n// Job classes for horizon-observability")
        ->and(scandir($this->bootstrap.'/cache'))->toBe(['.', '..', 'horizon-observability.php'])
        ->and($cache->generatedAt())->toBe(1792238400);
});

it('creates the cache directory when it is missing', function (): void {
    (new Filesystem)->deleteDirectory($this->bootstrap.'/cache');

    expect(freshJobClassCache()->write())->toBe(0)
        ->and(is_file($this->bootstrap.'/cache/horizon-observability.php'))->toBeTrue();
});

it('throws when the file cannot be written', function (): void {
    $this->app->useBootstrapPath($this->bootstrap.'/cache/not-a-directory');
    touch($this->bootstrap.'/cache/not-a-directory');

    freshJobClassCache()->write();
})->throws(RuntimeException::class, 'Cannot create the directory');

it('clears the file and the runtime cache', function (): void {
    $a = $this->classes->job('Alpha');
    $cache = freshJobClassCache();
    $cache->write();
    $key = JobClassCache::key([$this->classes->directory()]);
    Cache::put($key, ['App\\Jobs\\Stale'], 60);

    expect($cache->clear())->toBeTrue()
        ->and(is_file($cache->path()))->toBeFalse()
        ->and(Cache::has($key))->toBeFalse()
        ->and($cache->generatedAt())->toBeNull()
        ->and($cache->clear())->toBeFalse();

    $b = $this->classes->job('Beta');

    expect(freshJobClassCache()->classes())->toBe([$a, $b]);
});

it('ignores an unusable cache file and warns once', function (string $contents): void {
    $a = $this->classes->job('Alpha');
    writeJobClassCacheFile($this->bootstrap.'/cache/horizon-observability.php', $contents);

    expect(freshJobClassCache()->classes())->toBe([$a])
        ->and(freshJobClassCache()->generatedAt())->toBeNull()
        ->and($this->log->messages('warning'))->toHaveCount(1)
        ->and($this->log->messages('warning')[0])->toStartWith('horizon-observability: ');
})->with([
    'not an array' => ["<?php\n\nreturn 5;\n"],
    'an old format' => ["<?php\n\nreturn ['App\\\\Jobs\\\\Old'];\n"],
    'a class list with a non-string' => ["<?php\n\nreturn ['version' => 1, 'generated_at' => 1, 'classes' => [5]];\n"],
    'a file that does not parse' => ["<?php\n\nreturn [\n"],
    'a file that throws' => ["<?php\n\nthrow new RuntimeException('boom');\n"],
]);

it('keeps discovering when the cache store fails, and warns once', function (): void {
    $a = $this->classes->job('Alpha');
    // Not static: CacheManager::extend() binds the closure to the manager on Laravel 11, 12 and the first 13.x.
    Cache::extend('broken', fn (): Repository => Cache::repository(new class extends ArrayStore
    {
        public function get($key): mixed
        {
            throw new RuntimeException('cache down');
        }

        public function put($key, $value, $seconds): bool
        {
            throw new RuntimeException('cache down');
        }

        public function forget($key): bool
        {
            throw new RuntimeException('cache down');
        }
    }));
    config()->set('cache.stores.broken', ['driver' => 'broken']);
    config()->set('cache.default', 'broken');
    Cache::forgetDriver('broken');

    $cache = freshJobClassCache();

    expect($cache->classes())->toBe([$a])
        ->and($cache->classes())->toBe([$a])
        ->and($cache->write())->toBe(1)
        ->and($cache->clear())->toBeTrue()
        ->and($this->log->messages('warning'))->toBe(['horizon-observability: discovery.cache failed: RuntimeException: cache down']);
});
