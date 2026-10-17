<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Http\Middleware\InjectGrafanaLink;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
| WP7 (Arch Q7): InjectGrafanaLink inserts the "Grafana" sidebar item right after "Dashboard" in Horizon's page and
| leaves every other response untouched, including when a marker is missing (C16 rendering side). The fixtures are
| the verbatim horizon::layout sources of 5.22.0 (the original floor; the floor is 5.29.1 since the phase B
| integration) and the latest release (5.50.1); the sidebar markup is the same in every release in between.
*/

const MENU_LINK_URL = 'http://localhost:3000/d/horizon-observability';

/**
 * Horizon layout sources: the fixtures plus the installed version.
 *
 * @return array<string, array{0: string}>
 */
function menuLinkLayouts(): array
{
    $installed = dirname((string) (new ReflectionClass(Horizon::class))->getFileName(), 2).'/resources/views/layout.blade.php';

    return [
        'Horizon 5.22.0' => [(string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/menu-link/horizon-layout-v5.22.0.html')],
        'Horizon 5.50.1' => [(string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/menu-link/horizon-layout-v5.50.1.html')],
        'installed Horizon' => [(string) file_get_contents($installed)],
    ];
}

function menuLinkConfigure(?string $url, bool $enabled = true): void
{
    config()->set('horizon-observability.grafana.url', $url);
    config()->set('horizon-observability.enabled', $enabled);
    app()->forgetInstance(Config::class);
}

function menuLinkRequest(string $method = 'GET', ?string $route = InjectGrafanaLink::ROUTE): Request
{
    $request = Request::create('/horizon/dashboard', $method);

    if ($route !== null) {
        $matched = (new Route([$method], 'horizon/{view?}', static fn (): null => null))->name($route);
        $matched->bind($request);
        $request->setRouteResolver(static fn (): Route => $matched);
    }

    return $request;
}

function menuLinkPage(string $html, int $status = 200, ?string $type = 'text/html; charset=UTF-8'): Response
{
    $response = new Response($html, $status);
    $response->headers->set('Content-Type', $type);

    return $response;
}

function menuLinkRun(SymfonyResponse $response, ?Request $request = null): SymfonyResponse
{
    return app(InjectGrafanaLink::class)->handle($request ?? menuLinkRequest(), static fn (): SymfonyResponse => $response);
}

function menuLinkItem(string $url = MENU_LINK_URL): string
{
    return trim(view(InjectGrafanaLink::VIEW, ['url' => $url])->render());
}

/**
 * Offset right after the `</li>` that closes Horizon's "Dashboard" item.
 */
function menuLinkAfterDashboard(string $html): int
{
    $dashboard = strpos($html, '<span>Dashboard</span>');
    expect($dashboard)->toBeInt();

    return (int) strpos($html, '</li>', (int) $dashboard) + strlen('</li>');
}

/**
 * @return list<string>
 */
function menuLinkSidebarLabels(string $html): array
{
    $sidebar = (int) strpos($html, InjectGrafanaLink::SIDEBAR);
    $list = substr($html, $sidebar, (int) strpos($html, '</ul>', $sidebar) - $sidebar);
    preg_match_all('~<span>([^<]*)</span>~', $list, $matches);

    return $matches[1];
}

beforeEach(function (): void {
    menuLinkConfigure(MENU_LINK_URL);
});

it('renders the item like a Horizon sidebar item', function (): void {
    expect(menuLinkItem())->toBe(implode("\n", [
        '<li class="nav-item">',
        '    <a href="'.MENU_LINK_URL.'" target="_blank" rel="noopener" class="nav-link d-flex align-items-center">',
        '        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">',
        '            <path fill-rule="evenodd" d="M1 2.75A.75.75 0 0 1 1.75 2h16.5a.75.75 0 0 1 0 1.5H18v8.75A2.75 2.75 0 0 1 15.25 15h-1.072l.798 3.06a.75.75 0 0 1-1.452.38L13.41 18H6.59l-.114.44a.75.75 0 0 1-1.452-.38L5.823 15H4.75A2.75 2.75 0 0 1 2 12.25V3.5h-.25A.75.75 0 0 1 1 2.75ZM7.373 15l-.391 1.5h6.037l-.392-1.5H7.373Zm7.49-8.931a.75.75 0 0 1-.175 1.046 19.326 19.326 0 0 0-3.398 3.098.75.75 0 0 1-1.097.04L8.5 8.561l-2.22 2.22A.75.75 0 1 1 5.22 9.72l2.75-2.75a.75.75 0 0 1 1.06 0l1.664 1.663a20.786 20.786 0 0 1 3.122-2.74.75.75 0 0 1 1.046.176Z" clip-rule="evenodd" />',
        '        </svg>',
        '        <span>Grafana</span>',
        '    </a>',
        '</li>',
    ]));
});

it('escapes the URL in the item', function (): void {
    expect(menuLinkItem('http://grafana.test/d/x?orgId=1&var-queue=<b>"q"</b>'))
        ->toContain('href="http://grafana.test/d/x?orgId=1&amp;var-queue=&lt;b&gt;&quot;q&quot;&lt;/b&gt;"')
        ->not->toContain('<b>');
});

it('inserts the item right after "Dashboard" in the Horizon layout', function (string $layout): void {
    $html = InjectGrafanaLink::insert($layout, 'ITEM');
    $offset = menuLinkAfterDashboard($layout);

    expect($html)->toBe(substr($layout, 0, $offset)."\nITEM".substr($layout, $offset));
})->with(menuLinkLayouts());

it('adds the link after "Dashboard" through the middleware', function (string $layout): void {
    $response = menuLinkRun(menuLinkPage($layout));
    $html = (string) $response->getContent();

    expect(menuLinkSidebarLabels($html))->toBe(['Dashboard', 'Grafana', ...array_slice(menuLinkSidebarLabels($layout), 1)])
        ->and(substr($html, menuLinkAfterDashboard($layout), strlen(menuLinkItem()) + 1))->toBe("\n".menuLinkItem())
        ->and(str_replace("\n".menuLinkItem(), '', $html))->toBe($layout)
        ->and(substr_count($html, 'href="'.MENU_LINK_URL.'"'))->toBe(1);
})->with(menuLinkLayouts());

it('uses the first item end after the sidebar marker', function (): void {
    $html = '<ul><li>header</li></ul><div class="col-2 sidebar"><ul><li>Dashboard</li><li>Monitoring</li></ul></div>';

    expect(InjectGrafanaLink::insert($html, '<li>Grafana</li>'))
        ->toBe('<ul><li>header</li></ul><div class="col-2 sidebar"><ul><li>Dashboard</li>'."\n".'<li>Grafana</li><li>Monitoring</li></ul></div>');
});

it('leaves a page with missing markers untouched (C16 rendering side)', function (string $html): void {
    $response = menuLinkRun(menuLinkPage($html));

    expect(InjectGrafanaLink::insert($html, menuLinkItem()))->toBeNull()
        ->and($response->getContent())->toBe($html);
})->with([
    'no sidebar' => ['<div class="col-3 sidebar"><ul><li>Dashboard</li></ul></div>'],
    'sidebar marker only' => ['<div class="col-2 sidebar">'],
    'no item end after the sidebar' => ['<ul><li>a</li></ul><div class="col-2 sidebar"><nav><a>Dashboard</a></nav></div>'],
    'item end after the sidebar list' => ['<div class="col-2 sidebar"><ul><a>Dashboard</a></ul></div><ul><li>content</li></ul>'],
    'item end after the sidebar column' => ['<div class="col-2 sidebar"><nav><a>Dashboard</a></nav></div><ul><li>content</li></ul>'],
    'empty page' => [''],
]);

it('does not add the item twice', function (string $layout): void {
    $once = (string) menuLinkRun(menuLinkPage($layout))->getContent();
    $twice = (string) menuLinkRun(menuLinkPage($once))->getContent();

    expect($twice)->toBe($once)
        ->and(InjectGrafanaLink::insert($once, menuLinkItem()))->toBeNull()
        ->and(InjectGrafanaLink::insert($layout, ''))->toBeNull();
})->with(menuLinkLayouts());

it('acts only on a GET of horizon.index', function (string $method, ?string $route): void {
    $layout = menuLinkLayouts()['installed Horizon'][0];

    expect(menuLinkRun(menuLinkPage($layout), menuLinkRequest($method, $route))->getContent())->toBe($layout);
})->with([
    'POST' => ['POST', InjectGrafanaLink::ROUTE],
    'HEAD' => ['HEAD', InjectGrafanaLink::ROUTE],
    'PUT' => ['PUT', InjectGrafanaLink::ROUTE],
    'Horizon API route' => ['GET', 'horizon.stats.index'],
    'unnamed route' => ['GET', null],
]);

it('leaves a request without a route untouched', function (): void {
    $layout = menuLinkLayouts()['installed Horizon'][0];

    expect(menuLinkRun(menuLinkPage($layout), Request::create('/horizon'))->getContent())->toBe($layout);
});

it('acts only on 200 text/html responses', function (int $status, ?string $type, bool $injects): void {
    $layout = menuLinkLayouts()['installed Horizon'][0];
    $html = (string) menuLinkRun(menuLinkPage($layout, $status, $type))->getContent();

    expect($html !== $layout)->toBe($injects);
})->with([
    'html' => [200, 'text/html; charset=UTF-8', true],
    'html without charset' => [200, 'text/html', true],
    'html in capitals' => [200, 'TEXT/HTML; charset=utf-8', true],
    'not found' => [404, 'text/html; charset=UTF-8', false],
    'server error' => [500, 'text/html; charset=UTF-8', false],
    'redirect' => [302, 'text/html; charset=UTF-8', false],
    'no content' => [204, 'text/html; charset=UTF-8', false],
    'json' => [200, 'application/json', false],
    'plain text' => [200, 'text/plain; charset=UTF-8', false],
    'xhtml' => [200, 'application/xhtml+xml', false],
    'html prefix only' => [200, 'text/htmlx', false],
    'no content type' => [200, null, false],
]);

it('leaves the JSON API and other response classes untouched', function (Closure $make): void {
    $layout = menuLinkLayouts()['installed Horizon'][0];
    $response = $make($layout);
    $before = $response->getContent();

    expect(menuLinkRun($response)->getContent())->toBe($before);
})->with([
    'JsonResponse' => [static fn (string $layout): SymfonyResponse => new JsonResponse(['html' => $layout], 200, [], JSON_UNESCAPED_SLASHES)],
    'Symfony Response' => [static fn (string $layout): SymfonyResponse => new SymfonyResponse($layout, 200, ['Content-Type' => 'text/html; charset=UTF-8'])],
    'StreamedResponse' => [static fn (string $layout): SymfonyResponse => new StreamedResponse(static function () use ($layout): void {
        echo $layout;
    }, 200, ['Content-Type' => 'text/html; charset=UTF-8'])],
]);

it('leaves a compressed page untouched', function (): void {
    $layout = menuLinkLayouts()['installed Horizon'][0];
    $response = menuLinkPage($layout);
    $response->headers->set('Content-Encoding', 'gzip');

    expect(menuLinkRun($response)->getContent())->toBe($layout);
});

it('reads the validated URL at request time', function (?string $url, bool $enabled): void {
    $layout = menuLinkLayouts()['installed Horizon'][0];
    menuLinkConfigure($url, $enabled);

    expect(menuLinkRun(menuLinkPage($layout))->getContent())->toBe($layout);
})->with([
    'null' => [null, true],
    'empty' => ['', true],
    'blank' => ['   ', true],
    'javascript scheme' => ['javascript:alert(1)', true],
    'ftp scheme' => ['ftp://grafana.test/d/x', true],
    'no host' => ['http:///d/x', true],
    'not a URL' => ['grafana', true],
    'package disabled' => [MENU_LINK_URL, false],
]);

it('uses the trimmed URL', function (): void {
    menuLinkConfigure('  https://grafana.test/d/horizon  ');

    expect((string) menuLinkRun(menuLinkPage(menuLinkLayouts()['installed Horizon'][0]))->getContent())
        ->toContain('<a href="https://grafana.test/d/horizon" target="_blank"');
});

it('keeps the status, the headers and the original view', function (): void {
    $layout = menuLinkLayouts()['installed Horizon'][0];
    $view = view(InjectGrafanaLink::VIEW, ['url' => MENU_LINK_URL]);
    $response = menuLinkPage($layout);
    $response->original = $view;
    $response->headers->set('Content-Length', (string) strlen($layout));
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

    $result = menuLinkRun($response);

    expect($result)->toBe($response)
        ->and($response->original)->toBe($view)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('text/html; charset=UTF-8')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen((string) $response->getContent()))
        ->and(strlen((string) $response->getContent()))->toBe(strlen($layout) + 1 + strlen(menuLinkItem()));
});

it('does not add a Content-Length header', function (): void {
    $response = menuLinkPage(menuLinkLayouts()['installed Horizon'][0]);
    $response->headers->remove('Content-Length');

    menuLinkRun($response);

    expect($response->headers->has('Content-Length'))->toBeFalse();
});

it('passes the page through and logs once when rendering fails', function (): void {
    $log = new LogRecorder;
    $this->app->instance('log', $log);
    $views = Mockery::mock(ViewFactory::class);
    $views->shouldReceive('make')->andThrow(new RuntimeException('View [menu-link] not found.'));
    $this->app->instance(ViewFactory::class, $views);
    $layout = menuLinkLayouts()['installed Horizon'][0];

    $first = menuLinkRun(menuLinkPage($layout));
    $second = menuLinkRun(menuLinkPage($layout));

    expect($first->getContent())->toBe($layout)
        ->and($second->getContent())->toBe($layout)
        ->and($log->messages('warning'))->toBe(['horizon-observability: menu-link failed: RuntimeException: View [menu-link] not found.']);
});

it('passes the page through when even the failure report fails', function (): void {
    // Forgotten first: before Laravel 11.34, bind() of a resolved abstract rebuilt it at once (Container::rebound()),
    // so the throwing binding threw here, on Laravel 11.23.
    unset($this->app[Config::class], $this->app[FailureReporter::class]);
    $this->app->bind(Config::class, static fn (): never => throw new LogicException('broken container'));
    $this->app->bind(FailureReporter::class, static fn (): never => throw new LogicException('broken container'));
    $layout = menuLinkLayouts()['installed Horizon'][0];

    expect(menuLinkRun(menuLinkPage($layout))->getContent())->toBe($layout);
});
