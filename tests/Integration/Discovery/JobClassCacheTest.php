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

/*
| The job class cache (Arch Q6.9, C22): the deploy-time file wins, else the runtime cache (1 h, keyed by the scanned
| directories) serves every request, else live discovery runs. A fresh JobClassCache instance stands for a new PHP-FPM
| request: the pilot kept the list in a static property, so every request scanned the code again.
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

it('discovers the classes when nothing is cached', function (): void {
    $a = $this->classes->job('Alpha');
    $b = $this->classes->job('Beta');

    expect(freshJobClassCache()->classes())->toBe([$a, $b])
        ->and(Cache::get(JobClassCache::key([$this->classes->directory()])))->toBe([$a, $b]);
});

it('serves a fresh request from the runtime cache without scanning again (C22)', function (): void {
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

it('prefers the cache file and never scans while it exists (C22)', function (): void {
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
    Cache::extend('broken', static fn (): Repository => Cache::repository(new class extends ArrayStore
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
