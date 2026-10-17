<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Discovery;

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Throwable;

/**
 * Job classes from `bootstrap/cache/horizon-observability.php`, then the runtime cache (1 h), then live discovery
 * (Arch Q6.9, C22).
 *
 * - **Cache file.** Written at deploy time by `horizon-observability:cache` (hooked into `php artisan optimize` on
 *   Laravel >= 11.27.1) and deleted by `horizon-observability:clear` (`optimize:clear`). While it exists it is the
 *   answer: a scrape costs one opcache-friendly `require`, and discovery never runs on the request path. It is
 *   written atomically (temporary file plus rename) and holds `version`, `generated_at` and `classes`.
 * - **Runtime cache.** Without a usable file, the list is kept in the default cache store for
 *   {@see self::TTL_SECONDS}, keyed by a hash of the scanned directories, so one discovery serves every process and
 *   request (PHP-FPM keeps nothing between requests) until it expires.
 * - **Live discovery** ({@see JobClassFinder}) runs only when both miss, behind a scan marker in the same store
 *   ({@see self::SCAN_SUFFIX}, {@see self::TTL_SECONDS}): while the marker exists no other request scans and the
 *   list is empty. Discovery autoloads application classes, and a class that cannot be loaded (a trait of a
 *   dev-only package, a method incompatible with its parent) ends the process with an uncatchable fatal error; the
 *   marker it leaves behind turns that into one failed scrape per hour instead of every scrape, and a warning names
 *   the remedy. `horizon-observability:cache` and `:clear` drop the marker.
 *
 * A broken file or cache store never fails a scrape: it is logged (throttled) and the next source answers.
 *
 * @internal
 */
final readonly class JobClassCache
{
    /**
     * The cache file, relative to the bootstrap path.
     */
    public const FILE = 'cache/horizon-observability.php';

    /**
     * Format version of the cache file.
     */
    public const VERSION = 1;

    /**
     * Lifetime of the runtime cache entry, seconds (Arch Q3 internal constants).
     */
    public const TTL_SECONDS = 3600;

    /**
     * Prefix of the runtime cache key; the rest is the hash of the scanned directories.
     */
    public const KEY_PREFIX = 'horizon-observability:job-classes:v1:';

    /**
     * Suffix of the runtime cache key that marks a running (or dead) discovery; the value is its start, Unix seconds.
     */
    public const SCAN_SUFFIX = ':scan';

    /**
     * A scan marker older than this is reported as a discovery that did not finish.
     */
    public const STALE_SCAN_SECONDS = 60;

    public function __construct(
        private Application $app,
        private JobClassFinder $finder,
        private FailureReporter $reporter,
    ) {}

    /**
     * @return list<string>
     */
    public function classes(): array
    {
        $file = $this->read();

        if ($file !== null) {
            return $file['classes'];
        }

        $paths = $this->paths();
        $key = self::key($paths);
        $store = $this->store();

        try {
            $cached = $store?->get($key);

            if ($this->isClassList($cached)) {
                return $cached;
            }
        } catch (Throwable $e) {
            $this->reporter->report('discovery.cache', $e);
            $store = null;
        }

        $marked = false;

        try {
            $marked = $store?->add($key.self::SCAN_SUFFIX, now()->getTimestamp(), self::TTL_SECONDS) ?? false;

            if (! $marked && $store instanceof Repository && $this->scanBlocked($store->get($key.self::SCAN_SUFFIX))) {
                return [];
            }
        } catch (Throwable $e) {
            $this->reporter->report('discovery.cache', $e);
            $store = null;
        }

        try {
            $classes = $this->finder->find($paths);

            try {
                $store?->put($key, $classes, self::TTL_SECONDS);
            } catch (Throwable $e) {
                $this->reporter->report('discovery.cache', $e);
            }
        } finally {
            if ($marked) {
                $this->forget([$key.self::SCAN_SUFFIX]);
            }
        }

        return $classes;
    }

    /**
     * Path of the deploy-time cache file.
     */
    public function path(): string
    {
        return $this->app->bootstrapPath(self::FILE);
    }

    /**
     * Discover and write the cache file; the number of classes written. The runtime cache entry is dropped too.
     *
     * @throws RuntimeException when the file cannot be written
     */
    public function write(): int
    {
        $paths = $this->paths();
        $classes = $this->finder->find($paths);
        $path = $this->path();

        $this->writeAtomically($path, $this->export([
            'version' => self::VERSION,
            'generated_at' => now()->getTimestamp(),
            'classes' => $classes,
        ]));

        $this->forget([self::key($paths), self::key($paths).self::SCAN_SUFFIX]);

        return count($classes);
    }

    /**
     * Delete the cache file and the runtime cache; whether a file was deleted.
     *
     * @throws RuntimeException when the file exists but cannot be deleted
     */
    public function clear(): bool
    {
        $path = $this->path();
        $deleted = false;

        if (is_file($path)) {
            if (! @unlink($path)) {
                clearstatcache(true, $path);

                if (is_file($path)) {
                    throw new RuntimeException(sprintf('Cannot delete %s.', $path));
                }
            }

            $this->invalidate($path);
            $deleted = true;
        }

        $key = self::key($this->paths());
        $this->forget([$key, $key.self::SCAN_SUFFIX]);

        return $deleted;
    }

    /**
     * When the cache file was written (Unix seconds), or null without a usable file; for `check` and `about`.
     */
    public function generatedAt(): ?int
    {
        return $this->read()['generated_at'] ?? null;
    }

    /**
     * The runtime cache key for the scanned directories.
     *
     * @param  list<string>  $paths
     */
    public static function key(array $paths): string
    {
        return self::KEY_PREFIX.sha1(implode("\0", $paths));
    }

    /**
     * Whether a scan marker blocks this discovery: another request is scanning, or a scan died. A marker the store
     * did not keep (the `null` store) blocks nothing. An old one is reported. The Redis store stores a number without
     * serialising it, so the marker comes back as a numeric string there.
     */
    private function scanBlocked(mixed $startedAt): bool
    {
        if ($startedAt === null) {
            return false;
        }

        $startedAt = is_string($startedAt) && preg_match('/^\d{1,19}$/', $startedAt) === 1 ? (int) $startedAt : $startedAt;

        if (! is_int($startedAt) || now()->getTimestamp() - $startedAt >= self::STALE_SCAN_SECONDS) {
            $this->reporter->warnOnce('discovery.scan', sprintf(
                'Job class discovery did not finish: a class below job_class_paths probably cannot be loaded (a fatal '
                .'error ends the process). queue_job_classes stays empty until the next attempt, at most %d minutes '
                .'after the failed one. Run php artisan horizon-observability:cache to see the error, or narrow '
                .'job_class_paths.',
                intdiv(self::TTL_SECONDS, 60),
            ));
        }

        return true;
    }

    /**
     * The cache file, or null when it is missing or unusable (logged once per throttle window).
     *
     * @return array{generated_at: int, classes: list<string>}|null
     */
    private function read(): ?array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        try {
            $data = (static fn (string $file): mixed => require $file)($path);
        } catch (Throwable $e) {
            $this->reporter->report('discovery.file', $e);

            return null;
        }

        if (! is_array($data)
            || ($data['version'] ?? null) !== self::VERSION
            || ! is_int($data['generated_at'] ?? null)
            || ! $this->isClassList($data['classes'] ?? null)) {
            $this->reporter->warnOnce('discovery.file', sprintf(
                '%s is not a valid job class cache and is ignored; run php artisan horizon-observability:cache.',
                $path,
            ));

            return null;
        }

        return ['generated_at' => $data['generated_at'], 'classes' => $data['classes']];
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return $this->app->make(Config::class)->jobClassPaths;
    }

    private function store(): ?Repository
    {
        try {
            /** @var Factory $cache */
            $cache = $this->app->make('cache');

            return $cache->store();
        } catch (Throwable $e) {
            $this->reporter->report('discovery.cache', $e);

            return null;
        }
    }

    /**
     * Drop runtime cache entries; a failing store (e.g. unreachable at deploy time) is only logged.
     *
     * @param  list<string>  $keys
     */
    private function forget(array $keys): void
    {
        try {
            $store = $this->store();

            foreach ($keys as $key) {
                $store?->forget($key);
            }
        } catch (Throwable $e) {
            $this->reporter->report('discovery.cache', $e);
        }
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private function isClassList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $class) {
            if (! is_string($class) || $class === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function export(array $data): string
    {
        return '<?php'.PHP_EOL.PHP_EOL
            .'// Job classes for horizon-observability, written by php artisan horizon-observability:cache.'.PHP_EOL.PHP_EOL
            .'return '.var_export($data, true).';'.PHP_EOL;
    }

    /**
     * Write to a temporary file next to the target, then rename it over the target, so a concurrent reader sees the
     * old file or the new one, never a partial one.
     */
    private function writeAtomically(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the directory %s.', $directory));
        }

        $temporary = $directory.'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            if (@file_put_contents($temporary, $contents) !== strlen($contents) || ! @rename($temporary, $path)) {
                throw new RuntimeException(sprintf('Cannot write %s.', $path));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        $this->invalidate($path);
    }

    /**
     * Drop the file from this process's opcache, so a later `require` here reads the new contents.
     */
    private function invalidate(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
