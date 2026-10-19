<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Support;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Versions of the running stack and the capabilities that depend on them.
 *
 * Versions come from `InstalledVersions::getVersion()` and `version_compare()`, never from `satisfies()`, because
 * `composer/semver` is not a runtime dependency of the framework. A dev branch or an unknown version counts as the
 * older behaviour, which is always safe.
 *
 * @internal
 */
final class Versions
{
    public const PACKAGE = 'sushi-market/horizon-observability';

    /**
     * @var Closure(string): ?string package => normalised version (e.g. `5.50.1.0`)
     */
    private readonly Closure $normalized;

    /**
     * @var Closure(string): ?string package => pretty version (e.g. `v5.50.1`)
     */
    private readonly Closure $pretty;

    private ?bool $payloadCarriesDelay = null;

    /**
     * @param  string|null  $laravel  for tests; default `Application::VERSION`
     * @param  (Closure(string): ?string)|null  $normalized  for tests; default `InstalledVersions::getVersion()`
     * @param  (Closure(string): ?string)|null  $pretty  for tests; default `InstalledVersions::getPrettyVersion()`
     */
    public function __construct(
        private readonly Repository $config,
        private readonly ?string $laravel = null,
        ?Closure $normalized = null,
        ?Closure $pretty = null,
    ) {
        $this->normalized = $normalized ?? static fn (string $package): ?string => self::installed($package, false);
        $this->pretty = $pretty ?? static fn (string $package): ?string => self::installed($package, true);
    }

    public function laravel(): string
    {
        return $this->laravel ?? Application::VERSION;
    }

    /**
     * Installed Horizon version without a leading `v`, or null.
     */
    public function horizon(): ?string
    {
        return $this->withoutV(($this->pretty)('laravel/horizon'));
    }

    /**
     * Installed package version without a leading `v`, or null (e.g. a path repository without metadata).
     */
    public function package(): ?string
    {
        return $this->withoutV(($this->pretty)(self::PACKAGE));
    }

    public function php(): string
    {
        return PHP_VERSION;
    }

    /**
     * The Redis client of `database.redis.client` with its version: `phpredis 6.0.2`, `predis 3.7.0`, or the bare
     * name when the version is unknown.
     */
    public function redisClient(): string
    {
        $client = $this->config->get('database.redis.client', 'phpredis');
        $client = is_string($client) && $client !== '' ? $client : 'phpredis';

        $version = match ($client) {
            'phpredis' => phpversion('redis'),
            'predis' => $this->withoutV(($this->pretty)('predis/predis')),
            default => null,
        };

        return is_string($version) && $version !== '' ? $client.' '.$version : $client;
    }

    /**
     * Whether queue payloads carry `createdAt` and `delay` (Laravel >= 12.11 and Horizon >= 5.49). When false, the
     * legacy `ready_at` side channel is active. Computed once.
     */
    public function payloadCarriesDelay(): bool
    {
        if ($this->payloadCarriesDelay === null) {
            $horizon = ($this->normalized)('laravel/horizon');

            $this->payloadCarriesDelay = version_compare($this->laravel(), '12.11.0', '>=')
                && $horizon !== null
                && preg_match('/^\d+\.\d+/', $horizon) === 1
                && version_compare($horizon, '5.49.0.0', '>=');
        }

        return $this->payloadCarriesDelay;
    }

    /**
     * Whether `ServiceProvider::optimizes()` exists (Laravel >= 11.27.1).
     */
    public function supportsOptimize(): bool
    {
        return self::hasOptimize(ServiceProvider::class);
    }

    /**
     * @param  class-string  $class
     */
    public static function hasOptimize(string $class): bool
    {
        return method_exists($class, 'optimizes');
    }

    private static function installed(string $package, bool $pretty): ?string
    {
        try {
            if (! InstalledVersions::isInstalled($package)) {
                return null;
            }

            return $pretty ? InstalledVersions::getPrettyVersion($package) : InstalledVersions::getVersion($package);
        } catch (Throwable) {
            return null;
        }
    }

    private function withoutV(?string $version): ?string
    {
        if ($version === null || $version === '') {
            return null;
        }

        return preg_match('/^v\d/', $version) === 1 ? substr($version, 1) : $version;
    }
}
