<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Closure;
use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Facade;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Boots separate applications with providers in a fixed order and without package discovery, for the provider order
 * tests (Horizon's provider first or the package's): the test's own application is restored as the container and
 * facade root afterwards.
 * Static state (hooks, maintenance exceptions) is reset before and after, so the test's own application does not
 * leak into the inspected one.
 */
final class Apps
{
    /**
     * Config of every test application, set before any provider boots ({@see TestCase} and {@see self::inspect()};
     * `$config` overrides it): no test depends on the workbench state. The skeleton's `sqlite` file exists only after
     * `composer build` and then holds a `job_batches` table and the `database` cache store's rows, which would turn
     * batches on in auto mode and keep warnings throttled across runs; testbench 9.x gives such apps no `app.key`.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'app.key' => 'base64:ZIBKaaFjRAY84d2anlYbSY8TIYBWEm2aVHcbUHRTD3c=',
        'cache.default' => 'array',
        'database.default' => 'testing',
        'queue.batching.database' => 'testing',
        'queue.failed.database' => 'testing',
    ];

    /**
     * @template T
     *
     * @param  list<class-string>  $providers  registered in this order
     * @param  array<string, mixed>  $config  set before any provider boots (and so before the booting callbacks), over
     *                                        {@see self::DEFAULTS}
     * @param  Closure(Application): T  $inspect
     * @param  (Closure(Application): void)|null  $before  runs before any provider registers, e.g. to bind a
     *                                                     {@see LogRecorder} as `log`
     * @return T
     */
    public static function inspect(array $providers, array $config, Closure $inspect, ?Closure $before = null): mixed
    {
        $previous = Container::getInstance();
        HorizonObservability::flushState();

        try {
            $app = Testbench::create(
                resolvingCallback: static function (Application $app) use ($config, $before): void {
                    if ($before instanceof Closure) {
                        $before($app);
                    }

                    $app->booting(static function (Application $app) use ($config): void {
                        foreach ([...self::DEFAULTS, ...$config] as $key => $value) {
                            $app['config']->set($key, $value);
                        }
                    });
                },
                options: ['extra' => ['providers' => $providers, 'dont-discover' => ['*']]],
            );

            return $inspect($app);
        } finally {
            HorizonObservability::flushState();
            Env::enablePutenv();
            Container::setInstance($previous);
            Facade::clearResolvedInstances();

            if ($previous instanceof Application) {
                Facade::setFacadeApplication($previous);
            }
        }
    }

    /**
     * The routes as `route:cache` stores them: compiled and matched by Symfony's compiled matcher.
     */
    public static function compiled(Application $app): CompiledRouteCollection
    {
        /** @var Router $router */
        $router = $app['router'];
        $compiled = $router->getRoutes()->compile();

        return (new CompiledRouteCollection($compiled['compiled'], $compiled['attributes']))->setRouter($router)->setContainer($app);
    }

    /**
     * Name of the route a request matches, or null when none does.
     */
    public static function match(RouteCollectionInterface $routes, string $url, string $method = 'GET'): ?string
    {
        try {
            return $routes->match(Request::create($url, $method))->getName();
        } catch (HttpException) {
            return null;
        }
    }
}
