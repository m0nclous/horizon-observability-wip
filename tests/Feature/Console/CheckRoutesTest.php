<?php

declare(strict_types=1);

use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Tests\Support\Apps;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Laravel\Horizon\HorizonServiceProvider;

/*
| The route precedence of horizon-observability:check (Arch Q2.2) with a package domain (HORIZON_OBSERVABILITY_DOMAIN).
| The routes are bound to the domain as Route::getDomain() returns it (a leading http:// or https:// dropped), and
| Laravel compares it with the request host alone. check matches each endpoint on that host, in an application booted
| with the domain (the routes are registered at boot):
| - a domain written with its scheme is checked on its host (it was matched as http://https://..., T14 QA round 2);
| - a domain with route parameters ({tenant}.metrics.internal) is checked on a sample host the package route accepts,
|   or reported as not checked when it accepts none (it was checked on the literal "{tenant}.metrics.internal");
| - a domain no request host can equal (a port, a path, an upper-case scheme) is one error with the domain fix, not
|   six "no route answers it" errors with the provider fix.
| Every case proves with the router what it claims: the routes answer (or not) on that host.
*/

pest()->group('redis');

const CHECK_ROUTES_TOKEN = 'check-routes-token-0123456789';

/**
 * The Routes results of check in an application booted with the domain, as "status name: message", and the route
 * that answers each URL.
 *
 * @param  list<string>  $urls
 * @param  (Closure(Application): void)|null  $before
 * @param  list<class-string>  $providers
 * @return array{0: list<string>, 1: array<string, string|null>, 2: list<CheckResult>}
 */
function checkRoutesIn(string $domain, array $urls = [], ?Closure $before = null, array $providers = [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class], bool $compiled = false): array
{
    return Apps::inspect(
        $providers,
        ['app.url' => 'http://localhost', 'horizon-observability.domain' => $domain, 'horizon-observability.token' => CHECK_ROUTES_TOKEN],
        static function (Application $app) use ($urls, $compiled): array {
            /** @var Router $router */
            $router = $app['router'];

            if ($compiled) {
                // As route:cache stores them: Symfony's compiled matcher.
                $router->setCompiledRoutes($router->getRoutes()->compile());
            }

            $results = array_values(array_filter($app->make(Diagnostics::class)->check(), static fn (CheckResult $result): bool => $result->section === 'Routes'));
            $answers = [];

            foreach ($urls as $url) {
                $answers[$url] = Apps::match($router->getRoutes(), $url);
            }

            return [
                array_map(static fn (CheckResult $result): string => $result->status->value.' '.$result->name.': '.$result->message, $results),
                $answers,
                $results,
            ];
        },
        $before,
    );
}

it('checks the precedence on the host of a domain written with its scheme', function (array $providers, bool $compiled): void {
    [$routes, $answers, $results] = checkRoutesIn('https://metrics.internal', ['http://metrics.internal/horizon/observability/metrics', 'https://metrics.internal/horizon/observability/jobs/failed'], providers: $providers, compiled: $compiled);

    expect($answers)->toBe([
        'http://metrics.internal/horizon/observability/metrics' => 'horizon-observability.metrics',
        'https://metrics.internal/horizon/observability/jobs/failed' => 'horizon-observability.jobs',
    ])->and($routes)->toBe([
        'ok Precedence: /horizon/observability/{metrics,jobs/*,live} answer the package routes on metrics.internal',
        'info Endpoints: http://metrics.internal/horizon/observability/metrics, http://metrics.internal/horizon/observability/jobs/{list}, http://metrics.internal/horizon/observability/live',
    ])->and($results[0]->fix)->toBeNull();
})->with([
    'Horizon first' => [[HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class], false],
    'package first' => [[HorizonObservabilityServiceProvider::class, HorizonServiceProvider::class], false],
    'compiled routes' => [[HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class], true],
]);

it('exits 0 from check with a domain written with its scheme', function (): void {
    [$code, $report] = Apps::inspect(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        ['horizon-observability.domain' => 'http://metrics.internal', 'horizon-observability.token' => CHECK_ROUTES_TOKEN],
        static function (Application $app): array {
            /** @var Kernel $kernel */
            $kernel = $app->make(Kernel::class);
            $code = $kernel->call('horizon-observability:check', ['--json' => true]);

            return [$code, json_decode($kernel->output(), true, flags: JSON_THROW_ON_ERROR)];
        },
    );

    /** @var array{errors: int, checks: list<array{section: string, status: string}>} $report */
    expect($report['errors'])->toBe(0)
        ->and($code)->toBe(0)
        ->and(array_values(array_filter($report['checks'], static fn (array $check): bool => $check['section'] === 'Routes' && $check['status'] !== 'info'))[0])
        ->toMatchArray(['name' => 'Precedence', 'status' => 'ok']);
});

it('checks a domain with parameters on a sample host the package route accepts', function (?string $pattern, string $host): void {
    [$routes, $answers] = checkRoutesIn(
        '{tenant}.metrics.internal',
        ['http://'.$host.'/horizon/observability/metrics', 'http://metrics.internal/horizon/observability/metrics'],
        // A global pattern constrains the domain parameter of every route registered after it.
        static function (Application $app) use ($pattern): void {
            if ($pattern !== null) {
                $app['router']->pattern('tenant', $pattern);
            }
        },
    );

    expect($answers)->toBe([
        'http://'.$host.'/horizon/observability/metrics' => 'horizon-observability.metrics',
        'http://metrics.internal/horizon/observability/metrics' => 'horizon.index',
    ])->and($routes[0])->toBe('ok Precedence: /horizon/observability/{metrics,jobs/*,live} answer the package routes on '.$host.' (a sample host of {tenant}.metrics.internal)');
})->with([
    'any value' => [null, 'acme.metrics.internal'],
    'digits' => ['[0-9]+', '1.metrics.internal'],
    'a UUID' => ['[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}', '00000000-0000-4000-8000-000000000000.metrics.internal'],
]);

it('says the precedence was not checked when the package route accepts no sample host', function (): void {
    [$routes, , $results] = checkRoutesIn(
        '{tenant}.metrics.internal',
        before: static function (Application $app): void {
            $app['router']->pattern('tenant', 'shop-[a-z]{2}');
        },
    );

    expect($routes[0])->toBe('info Precedence: not checked: no sample value (acme, 1, 00000000-0000-4000-8000-000000000000) for the parameters of the domain {tenant}.metrics.internal matches the package route')
        ->and($results[0]->fix)->toBe('Run php artisan route:list --path=horizon/observability and check that the package routes come first for your hosts.')
        ->and(array_filter($routes, static fn (string $line): bool => str_starts_with($line, 'error ')))->toBe([]);
});

it('reports a route shadowing the package on the sample host of a domain with parameters', function (): void {
    [$routes, , $results] = checkRoutesIn(
        '{tenant}.metrics.internal',
        ['http://acme.metrics.internal/horizon/observability/metrics'],
        // Registered before the package: it comes first on the same host and path.
        static function (Application $app): void {
            $app['router']->domain('{account}.metrics.internal')->get('horizon/observability/metrics', static fn (): string => 'app')->name('app.metrics');
        },
    );

    expect($routes[0])->toBe('error GET /horizon/observability/metrics: answered by app.metrics (horizon/observability/metrics) instead of horizon-observability.metrics on acme.metrics.internal (a sample host of {tenant}.metrics.internal)')
        ->and($results[0]->fix)->toContain('HORIZON_OBSERVABILITY_PATH');
});

it('reports a domain no request host can equal as one error with the domain fix', function (string $domain, string $bound, string $url): void {
    [$routes, $answers, $results] = checkRoutesIn($domain, [$url, 'http://metrics.internal/horizon/observability/metrics']);

    // The routes answer on no host: Laravel compares the domain with the host alone.
    expect(array_unique(array_values($answers)))->toBe(['horizon.index'])
        ->and($routes[0])->toBe(sprintf('error Domain: the routes are bound to the domain "%s", which no request host equals: Laravel compares it with the host alone (no port, path or upper-case scheme), so every endpoint gets a 404', $bound))
        ->and($results[0]->fix)->toBe('Set HORIZON_OBSERVABILITY_DOMAIN to the host alone, e.g. metrics.example.com.')
        ->and($routes)->toHaveCount(2)
        ->and($routes[1])->toStartWith('info Endpoints: ');
})->with([
    'a port' => ['metrics.internal:8080', 'metrics.internal:8080', 'http://metrics.internal:8080/horizon/observability/metrics'],
    'a trailing slash' => ['metrics.internal/', 'metrics.internal/', 'http://metrics.internal/horizon/observability/metrics'],
    'an upper-case scheme' => ['HTTPS://metrics.internal', 'HTTPS://metrics.internal', 'https://metrics.internal/horizon/observability/metrics'],
]);

it('checks a domain that is an IP address on that address', function (string $domain, string $url): void {
    [$routes, $answers] = checkRoutesIn($domain, [$url]);

    expect($answers[$url])->toBe('horizon-observability.metrics')
        ->and($routes[0])->toBe('ok Precedence: /horizon/observability/{metrics,jobs/*,live} answer the package routes on '.$domain);
})->with([
    'IPv4' => ['10.0.0.5', 'http://10.0.0.5/horizon/observability/metrics'],
    'IPv6' => ['[::1]', 'http://[::1]/horizon/observability/metrics'],
]);

it('reports missing routes on the domain host with the provider fix', function (): void {
    $routes = Apps::inspect(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        ['horizon-observability.domain' => 'https://metrics.internal', 'horizon-observability.token' => CHECK_ROUTES_TOKEN],
        static function (Application $app): array {
            $app['router']->setRoutes(new RouteCollection);

            return array_values(array_filter($app->make(Diagnostics::class)->check(), static fn (CheckResult $result): bool => $result->section === 'Routes'));
        },
    );

    expect($routes[0]->message)->toBe('no route answers it on metrics.internal')
        ->and($routes[0]->fix)->toContain('Register the package provider');
});
