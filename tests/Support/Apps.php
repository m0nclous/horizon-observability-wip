<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Closure;
use DF\HorizonObservability\HorizonObservability;
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
 * Boots separate applications with providers in a fixed order and without package discovery, for the provider
 * order tests (Arch Q2.2): the test's own application is restored as the container and facade root afterwards.
 * Static state (hooks, maintenance exceptions) is reset before and after, so the test's own application does not
 * leak into the inspected one.
 */
final class Apps
{
    /**
     * @template T
     *
     * @param  list<class-string>  $providers  registered in this order
     * @param  array<string, mixed>  $config  set before any provider boots (and so before the booting callbacks)
     * @param  Closure(Application): T  $inspect
     * @return T
     */
    public static function inspect(array $providers, array $config, Closure $inspect): mixed
    {
        $previous = Container::getInstance();
        HorizonObservability::flushState();

        try {
            $app = Testbench::create(
                resolvingCallback: static function (Application $app) use ($config): void {
                    $app->booting(static function (Application $app) use ($config): void {
                        foreach ($config as $key => $value) {
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
