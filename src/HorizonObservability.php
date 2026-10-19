<?php

declare(strict_types=1);

namespace DF\HorizonObservability;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;

/**
 * PUBLIC static hooks of the package, called from the app's `HorizonServiceProvider::boot()`
 * like `Horizon::auth()`.
 */
final class HorizonObservability
{
    /**
     * @var (Closure(Request): bool)|null
     */
    private static ?Closure $authCallback = null;

    /**
     * @var (Closure(?string, string): string)|null
     */
    private static ?Closure $batchKindResolver = null;

    /**
     * Decide access to the endpoints with a callback instead of tokens and IPs (mTLS or VPN headers, a custom gate).
     * The callback alone decides: true allows, false answers 403. null restores the built-in policy.
     *
     * @param  (Closure(Request): bool)|null  $callback
     */
    public static function auth(?Closure $callback): void
    {
        self::$authCallback = $callback;
    }

    /**
     * Replace the default batch kind normaliser, e.g. to hash or strip personal data from batch names. The callback
     * gets the batch name (null when unnamed) and id, and returns the `batch` label value.
     *
     * @param  (Closure(?string $name, string $id): string)|null  $callback
     */
    public static function resolveBatchKindUsing(?Closure $callback): void
    {
        self::$batchKindResolver = $callback;
    }

    /**
     * @internal
     *
     * @return (Closure(Request): bool)|null
     */
    public static function authCallback(): ?Closure
    {
        return self::$authCallback;
    }

    /**
     * @internal
     *
     * @return (Closure(?string, string): string)|null
     */
    public static function batchKindResolver(): ?Closure
    {
        return self::$batchKindResolver;
    }

    /**
     * Reset the static state between tests: both hooks and the maintenance-mode exceptions.
     *
     * @internal
     */
    public static function flushState(): void
    {
        self::$authCallback = null;
        self::$batchKindResolver = null;

        PreventRequestsDuringMaintenance::flushState();
    }
}
