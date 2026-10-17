<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Http\Middleware\InjectGrafanaLink;
use DF\HorizonObservability\Tests\Support\Apps;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonServiceProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Workbench\App\Providers\WorkbenchServiceProvider;

/*
| WP7 (Arch Q7, Q9): the "Grafana" link is in the real Horizon page (GET {horizon.path} and the SPA paths of
| horizon.index) right after "Dashboard", in both provider orders, with the web middleware kept (C16); it is absent
| without a valid URL or when the package is disabled; Horizon's JSON API is untouched; a layout without the markers
| is passed through untouched (C16 rendering side). Each case boots its own application (Tests\Support\Apps) with the
| config set before any provider boots, as an application's config file would be.
*/

const HORIZON_MENU_LINK_URL = 'http://localhost:3000/d/horizon-observability';

dataset('menu link provider orders', [
    'Horizon first' => [[HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class]],
    'package first' => [[HorizonObservabilityServiceProvider::class, HorizonServiceProvider::class]],
]);

/**
 * Responses of the given requests in a fresh application (the workbench provider opens Horizon's gate).
 *
 * @param  list<class-string>  $providers
 * @param  array<string, mixed>  $config
 * @param  list<string>  $uris
 * @param  (Closure(Application): void)|null  $prepare  runs after boot, before the first request
 * @return array<string, array{status: int, type: string|null, content: string, cookies: list<string>, middleware: list<string>}>
 */
function horizonMenuLinkPages(array $providers, array $config, array $uris, ?Closure $prepare = null): array
{
    $public = sys_get_temp_dir().'/ho-menu-link-'.getmypid();

    try {
        // The web group encrypts its cookies; older testbench skeletons have no APP_KEY.
        $config = ['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), ...$config];

        return Apps::inspect([WorkbenchServiceProvider::class, ...$providers], $config, static function (Application $app) use ($uris, $prepare, $public): array {
            horizonMenuLinkPublishLegacyAssets($app, $public);

            if ($prepare instanceof Closure) {
                $prepare($app);
            }

            $kernel = $app->make(HttpKernel::class);
            /** @var Router $router */
            $router = $app['router'];
            $pages = [];

            foreach ($uris as $uri) {
                $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => str_contains($uri, '/api/') ? 'application/json' : 'text/html']);
                $response = $kernel->handle($request);
                $route = $request->route();

                $pages[$uri] = [
                    'status' => $response->getStatusCode(),
                    'type' => $response->headers->get('Content-Type'),
                    'content' => (string) $response->getContent(),
                    'cookies' => array_map(static fn (Cookie $cookie): string => $cookie->getName(), $response->headers->getCookies()),
                    'middleware' => $route instanceof Route ? array_values(array_map(strval(...), $router->gatherRouteMiddleware($route))) : [],
                ];

                $kernel->terminate($request, $response);
            }

            return $pages;
        });
    } finally {
        File::deleteDirectory($public);
    }
}

/**
 * Horizon below 5.25 renders its assets from the published mix manifest and throws when it is missing; publish the
 * installed one into a temporary public path (a no-op on 5.25+, which inlines its assets).
 */
function horizonMenuLinkPublishLegacyAssets(Application $app, string $public): void
{
    $manifest = dirname((string) (new ReflectionClass(Horizon::class))->getFileName(), 2).'/public/mix-manifest.json';

    if (! is_file($manifest)) {
        return;
    }

    File::ensureDirectoryExists($public.'/vendor/horizon');
    File::copy($manifest, $public.'/vendor/horizon/mix-manifest.json');
    $app->usePublicPath($public);
}

/**
 * Sidebar item labels in order.
 *
 * @return list<string>
 */
function horizonMenuLinkLabels(string $html): array
{
    $sidebar = strpos($html, InjectGrafanaLink::SIDEBAR);
    expect($sidebar)->toBeInt();
    $list = substr($html, (int) $sidebar, (int) strpos($html, '</ul>', (int) $sidebar) - (int) $sidebar);
    preg_match_all('~<span>([^<]*)</span>~', $list, $matches);

    return $matches[1];
}

/**
 * The page without its per-request CSRF token.
 */
function horizonMenuLinkStable(string $html): string
{
    return (string) preg_replace('~<meta name="csrf-token" content="[^"]*">~', '<meta name="csrf-token" content="">', $html);
}

it('adds the link right after "Dashboard" in the real Horizon page and keeps web (C16)', function (array $providers): void {
    $uris = ['/horizon', '/horizon/dashboard', '/horizon/failed'];
    $with = horizonMenuLinkPages($providers, ['horizon-observability.grafana.url' => HORIZON_MENU_LINK_URL], $uris);
    $without = horizonMenuLinkPages($providers, [], $uris);

    $item = "\n".trim(view(InjectGrafanaLink::VIEW, ['url' => HORIZON_MENU_LINK_URL])->render());

    foreach ($uris as $uri) {
        $page = $with[$uri];
        $labels = horizonMenuLinkLabels($without[$uri]['content']);
        $dashboardEnd = (int) strpos($page['content'], '</li>', (int) strpos($page['content'], '<span>Dashboard</span>')) + strlen('</li>');

        expect($page['status'])->toBe(200, $uri)
            ->and($page['middleware'])->toContain(InjectGrafanaLink::class)
            // web kept: the web group's CSRF cookie is set
            ->and($page['cookies'])->toContain('XSRF-TOKEN')
            ->and($labels[0])->toBe('Dashboard')
            ->and(horizonMenuLinkLabels($page['content']))->toBe(['Dashboard', 'Grafana', ...array_slice($labels, 1)])
            ->and(substr($page['content'], $dashboardEnd, strlen($item)))->toBe($item)
            ->and($item)->toContain('<a href="'.HORIZON_MENU_LINK_URL.'" target="_blank" rel="noopener"')
            ->and(substr_count($page['content'], '<span>Grafana</span>'))->toBe(1)
            // nothing else changes: without the item it is Horizon's own page
            ->and(horizonMenuLinkStable(str_replace($item, '', $page['content'])))->toBe(horizonMenuLinkStable($without[$uri]['content']));
    }
})->with('menu link provider orders');

it('escapes the URL in the real Horizon page', function (): void {
    $page = horizonMenuLinkPages(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        ['horizon-observability.grafana.url' => 'https://grafana.test/d/horizon-observability?orgId=1&var-queue=default'],
        ['/horizon'],
    )['/horizon'];

    expect($page['content'])->toContain('<a href="https://grafana.test/d/horizon-observability?orgId=1&amp;var-queue=default" target="_blank" rel="noopener"');
});

it('has no link without a Grafana URL', function (array $providers, ?string $url): void {
    $page = horizonMenuLinkPages($providers, ['horizon-observability.grafana.url' => $url], ['/horizon'])['/horizon'];

    expect($page['status'])->toBe(200)
        ->and($page['middleware'])->not->toContain(InjectGrafanaLink::class)
        ->and($page['cookies'])->toContain('XSRF-TOKEN')
        ->and(horizonMenuLinkLabels($page['content']))->not->toContain('Grafana')
        ->and($page['content'])->not->toContain('<span>Grafana</span>');
})->with('menu link provider orders')->with(['null' => [null], 'empty' => [''], 'blank' => ['  ']]);

it('has no link with an invalid Grafana URL', function (string $url): void {
    $page = horizonMenuLinkPages(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        ['horizon-observability.grafana.url' => $url],
        ['/horizon'],
    )['/horizon'];

    // The boot path does not validate (Arch Q3), so the middleware is attached; the validated Config URL is null.
    expect($page['status'])->toBe(200)
        ->and($page['middleware'])->toContain(InjectGrafanaLink::class)
        ->and($page['content'])->not->toContain('<span>Grafana</span>')
        ->and($page['content'])->not->toContain($url);
})->with(['javascript scheme' => ['javascript:alert(document.domain)'], 'ftp scheme' => ['ftp://grafana.test/d/x'], 'no scheme' => ['grafana.test/d/x']]);

it('has no link when the package is disabled', function (array $providers): void {
    $page = horizonMenuLinkPages($providers, [
        'horizon-observability.enabled' => false,
        'horizon-observability.grafana.url' => HORIZON_MENU_LINK_URL,
    ], ['/horizon'])['/horizon'];

    expect($page['status'])->toBe(200)
        ->and($page['middleware'])->not->toContain(InjectGrafanaLink::class)
        ->and($page['content'])->not->toContain('<span>Grafana</span>');
})->with('menu link provider orders');

it('reads the URL at request time', function (): void {
    $page = horizonMenuLinkPages(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        ['horizon-observability.grafana.url' => HORIZON_MENU_LINK_URL],
        ['/horizon'],
        static function (Application $app): void {
            $app['config']->set('horizon-observability.grafana.url', null);
            $app->forgetInstance(Config::class);
        },
    )['/horizon'];

    expect($page['status'])->toBe(200)
        ->and($page['middleware'])->toContain(InjectGrafanaLink::class)
        ->and($page['content'])->not->toContain('<span>Grafana</span>');
});

it('passes a Horizon layout without the markers through untouched (C16 rendering side)', function (string $layout): void {
    $views = sys_get_temp_dir().'/ho-menu-link-views-'.getmypid().'-'.md5($layout);
    File::ensureDirectoryExists($views);
    File::put($views.'/layout.blade.php', $layout);

    try {
        $page = horizonMenuLinkPages(
            [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
            ['horizon-observability.grafana.url' => HORIZON_MENU_LINK_URL],
            ['/horizon'],
            static function (Application $app) use ($views): void {
                $app['view']->prependNamespace('horizon', $views);
            },
        )['/horizon'];
    } finally {
        File::deleteDirectory($views);
    }

    expect($page['status'])->toBe(200)
        ->and($page['middleware'])->toContain(InjectGrafanaLink::class)
        ->and($page['content'])->toBe($layout);
})->with([
    'renamed sidebar' => ['<div class="col-3 sidebar"><ul class="nav"><li class="nav-item"><span>Dashboard</span></li></ul></div>'],
    'sidebar without list items' => ['<div class="col-2 sidebar"><nav><a href="#">Dashboard</a></nav></div><ul><li>content</li></ul>'],
]);

it('leaves the Horizon JSON API untouched', function (array $providers): void {
    $uris = ['/horizon/api/stats', '/horizon/api/masters', '/horizon/api/workload', '/horizon/api/monitoring', '/horizon/api/jobs/pending'];
    $with = horizonMenuLinkPages($providers, ['horizon-observability.grafana.url' => HORIZON_MENU_LINK_URL], $uris);
    $without = horizonMenuLinkPages($providers, [], $uris);

    foreach ($uris as $uri) {
        expect($with[$uri]['status'])->toBe(200, $uri)
            ->and($with[$uri]['middleware'])->toContain(InjectGrafanaLink::class)
            ->and($with[$uri]['type'])->toBe('application/json')
            ->and($with[$uri]['content'])->toBe($without[$uri]['content'])
            ->and($with[$uri]['content'])->not->toContain('Grafana')
            ->and(json_decode($with[$uri]['content'], true, flags: JSON_THROW_ON_ERROR))->toBeArray();
    }
})->with('menu link provider orders')->group('redis');
