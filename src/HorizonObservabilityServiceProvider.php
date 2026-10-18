<?php

declare(strict_types=1);

namespace DF\HorizonObservability;

use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Attempts\AttemptTracker;
use DF\HorizonObservability\Attempts\LegacyReadyAt;
use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Console\CacheCommand;
use DF\HorizonObservability\Console\CheckCommand;
use DF\HorizonObservability\Console\ClearCommand;
use DF\HorizonObservability\Console\FlushCommand;
use DF\HorizonObservability\Console\GrafanaCommand;
use DF\HorizonObservability\Console\InstallCommand;
use DF\HorizonObservability\Console\StreamCommand;
use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\Middleware\InjectGrafanaLink;
use DF\HorizonObservability\Http\RoutePath;
use DF\HorizonObservability\Redis\CircuitBreaker;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Support\Versions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

/**
 * Wiring of the package (Arch Q2.2, Q7). PUBLIC as a class name only (package discovery).
 *
 * Nothing in `register()` or `boot()` touches Redis or the DB or resolves a collector, and the boot path reads only
 * `enabled`, `collectors.*`, `grafana.url`, `path`, `domain`, `middleware`, `available_during_maintenance` and
 * `live.enabled` with safe casts and no validation, so a monitoring typo can never break the host app.
 *
 * The routes and the Horizon menu middleware are registered from an *application booting callback*: it runs after
 * every provider's `register()` and before any provider's `boot()`, so the routes precede Horizon's catch-all route
 * in either provider order, with and without `route:cache`, and the middleware is in `horizon.middleware` before
 * Horizon reads it. A provider registered too late (after boot, or from another provider's `boot()`, when the
 * booting callbacks have already fired) still registers everything, from a `booted` fallback, and logs a warning.
 */
final class HorizonObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Whether the booting callback (or its late fallback) has run for this provider instance.
     */
    private bool $wired = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/horizon-observability.php', 'horizon-observability');

        $this->app->singleton(Config::class, static fn (Application $app): Config => Config::fromRepository(self::repository($app)));
        // In the console (commands, workers) only: an HTTP request resolves the snapshot before Authorize decides, and
        // the throttled warning's cache round trip must not be paid by denied requests (Arch Q2.4). An authorised
        // scrape logs the problems instead (Exporter::scrape()).
        $this->app->afterResolving(Config::class, static function (Config $config, Application $app): void {
            if ($config->problems !== [] && $app->runningInConsole()) {
                $app->make(FailureReporter::class)->configProblems($config);
            }
        });

        $this->app->singleton(RedisClients::class);
        $this->app->singleton(FailureReporter::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(Versions::class);
        $this->app->singleton(Lua::class, static fn (): Lua => new Lua);
        $this->app->singleton(Keys::class, static fn (): Keys => new Keys);
        $this->app->singleton(Topology::class);
        $this->app->singleton(AttemptTracker::class);
        $this->app->singleton(AttemptStore::class);
        $this->app->singleton(BatchLinks::class);
        $this->app->singleton(JobClassCache::class);

        if ($this->app->isBooted()) {
            $this->beforeProvidersBoot(late: true);

            return;
        }

        $this->app->booting(fn () => $this->beforeProvidersBoot());

        // Registered while the providers boot (e.g. from another provider's boot()): the booting callbacks have
        // already fired and this one never runs, so wire up late, with the warning, instead of registering nothing.
        $this->app->booted(function (): void {
            if (! $this->wired) {
                $this->beforeProvidersBoot(late: true);
            }
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'horizon-observability');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/horizon-observability.php' => $this->app->configPath('horizon-observability.php'),
            ], 'horizon-observability-config');

            $this->publishes([
                __DIR__.'/../resources/grafana' => $this->app->basePath('grafana'),
            ], 'horizon-observability-grafana');

            $this->commands(self::packageCommands());

            if (Versions::hasOptimize(self::class)) {
                $this->optimizes(optimize: 'horizon-observability:cache', clear: 'horizon-observability:clear', key: 'horizon-observability');
            }
        }

        AboutCommand::add('Horizon Observability', fn (): array => $this->app->make(Diagnostics::class)->about());

        $config = self::repository($this->app);

        if (! Config::flag($config->get('horizon-observability.enabled', true), true)) {
            return;
        }

        if (Config::flag($config->get('horizon-observability.available_during_maintenance', true), true)) {
            PreventRequestsDuringMaintenance::except(self::maintenanceExceptions($config));
        }

        if (Config::flag($config->get('horizon-observability.collectors.attempts', true), true)) {
            Event::subscribe(AttemptTracker::class);

            if (! $this->app->make(Versions::class)->payloadCarriesDelay()) {
                Event::listen(JobQueued::class, [LegacyReadyAt::class, 'onQueued']);
            }
        }

        if ($this->batchesEnabled($config->get('horizon-observability.collectors.batches'))) {
            Event::listen(JobQueued::class, [BatchLinks::class, 'onQueued']);
        }
    }

    /**
     * Boot-time reading of `collectors.batches` (true, false or null = auto): off only for a value that casts to
     * false (`false`, `0`, `'false'`, `'off'`, ...), as {@see Config} reads it; null, `'auto'` and anything
     * unparsable keep the auto default, so the links are recorded.
     */
    private function batchesEnabled(mixed $value): bool
    {
        return $value === null || Config::flag($value, true);
    }

    /**
     * The seven package commands in Arch Q7 order.
     *
     * @return list<class-string>
     */
    public static function packageCommands(): array
    {
        return [
            InstallCommand::class,
            CheckCommand::class,
            GrafanaCommand::class,
            CacheCommand::class,
            ClearCommand::class,
            FlushCommand::class,
            StreamCommand::class,
        ];
    }

    /**
     * Paths `PreventRequestsDuringMaintenance` lets through (Arch Q2.4, Addendum R4.3). The four job lists are named,
     * not `jobs/*`, so an unknown list name still answers 503 instead of reaching Horizon's catch-all.
     *
     * @return list<string>
     */
    public static function maintenanceExceptions(Repository $config): array
    {
        $base = RoutePath::base($config);
        $paths = [$base.'/metrics', ...array_map(static fn (JobList $list): string => $base.'/jobs/'.$list->value, JobList::cases())];

        if (Config::flag($config->get('horizon-observability.live.enabled', true), true)) {
            $paths[] = $base.'/live';
        }

        return $paths;
    }

    /**
     * The booting callback (Arch Q2.2): menu middleware, then the routes unless they are cached.
     */
    private function beforeProvidersBoot(bool $late = false): void
    {
        $this->wired = true;
        $config = self::repository($this->app);

        if (! Config::flag($config->get('horizon-observability.enabled', true), true)) {
            return; // inert: no routes, no menu link
        }

        $this->appendHorizonMenuMiddleware($config);

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return; // our routes are inside the cache
        }

        if ($late || Route::has('horizon.index')) {
            $this->app->make(FailureReporter::class)->warnOnce(
                'routes.late',
                "provider registered after boot; Horizon's catch-all route may shadow the endpoints.",
            );
        }

        $domain = $config->get('horizon-observability.domain');

        Route::group([
            'prefix' => RoutePath::base($config),
            'domain' => is_string($domain) && trim($domain) !== '' ? trim($domain) : null,
        ], __DIR__.'/../routes/horizon-observability.php');
    }

    /**
     * C16: append the menu-link middleware to `horizon.middleware`, idempotently and always as an array (Horizon
     * 5.45+ spreads it), only when `grafana.url` is set. Horizon reads the value in its own `boot()`.
     */
    private function appendHorizonMenuMiddleware(Repository $config): void
    {
        $url = $config->get('horizon-observability.grafana.url');

        if (! is_string($url) || trim($url) === '' || ! class_exists(Horizon::class)) {
            return;
        }

        $middleware = array_values(Arr::wrap($config->get('horizon.middleware', ['web'])));

        if (! in_array(InjectGrafanaLink::class, $middleware, true)) {
            $middleware[] = InjectGrafanaLink::class;
        }

        $config->set('horizon.middleware', $middleware);
    }

    private static function repository(Application $app): Repository
    {
        /** @var Repository */
        return $app->make('config');
    }
}
