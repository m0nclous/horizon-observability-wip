<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http\Middleware;

use Closure;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Adds a "Grafana" link right after "Dashboard" in Horizon's sidebar. The provider
 * appends it to `horizon.middleware` when `grafana.url` is set, so it runs on every Horizon route.
 *
 * Horizon has no hook for sidebar items, so the item is inserted into the rendered `horizon::layout` page; a copy
 * of the layout would drift from Horizon. It acts only on a GET of `horizon.index` (the dashboard page, never the
 * JSON API) answered by an {@see HttpResponse} with status 200 and uncompressed `text/html` content, and only when
 * the validated {@see Config::$grafanaUrl} is set at request time. The item is the view {@see self::VIEW}, placed
 * after the first {@see self::ITEM_END} that follows {@see self::SIDEBAR} inside the sidebar list. When a marker is
 * missing (a changed Horizon layout) or anything fails, the response is passed on untouched: the link silently
 * disappears, it never breaks Horizon. A failure is logged, throttled.
 *
 * @internal
 */
final readonly class InjectGrafanaLink
{
    /**
     * Horizon's sidebar column (`horizon::layout`, the same markup from 5.22 through 5.50).
     */
    public const SIDEBAR = 'class="col-2 sidebar"';

    /**
     * End of a sidebar item; the first one after {@see self::SIDEBAR} closes "Dashboard".
     */
    public const ITEM_END = '</li>';

    /**
     * Ends of the sidebar list and column: an item end after one of them is outside the sidebar list.
     */
    public const SIDEBAR_ENDS = ['</ul>', '</div>'];

    /**
     * The route of Horizon's dashboard page (its catch-all `GET {horizon.path}/{view?}`).
     */
    public const ROUTE = 'horizon.index';

    /**
     * The sidebar item (PUBLIC view name); it receives the dashboard URL as `$url`.
     */
    public const VIEW = 'horizon-observability::menu-link';

    public function __construct(private Container $container) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->inject($request, $response);
        } catch (Throwable $e) {
            $this->report($e);
        }

        return $response;
    }

    /**
     * Insert the item into a Horizon page: after the first item end that follows the sidebar marker. Null when a
     * marker is missing, when that item end lies outside the sidebar list (a list or column end comes first), or
     * when the page already has the item.
     */
    public static function insert(string $html, string $item): ?string
    {
        $sidebar = strpos($html, self::SIDEBAR);

        if ($sidebar === false || $item === '' || str_contains($html, $item)) {
            return null;
        }

        $itemEnd = strpos($html, self::ITEM_END, $sidebar);

        if ($itemEnd === false) {
            return null;
        }

        $firstItem = substr($html, $sidebar, $itemEnd - $sidebar);

        foreach (self::SIDEBAR_ENDS as $end) {
            if (str_contains($firstItem, $end)) {
                return null;
            }
        }

        return substr_replace($html, "\n".$item, $itemEnd + strlen(self::ITEM_END), 0);
    }

    private function inject(Request $request, Response $response): void
    {
        if (! $response instanceof HttpResponse || ! $this->isHorizonPage($request, $response)) {
            return;
        }

        $url = $this->grafanaUrl();
        $content = $response->getContent();

        if ($url === null || ! is_string($content)) {
            return;
        }

        $html = self::insert($content, trim($this->render($url)));

        if ($html === null) {
            return;
        }

        // setContent() replaces the original (the layout view) with the string; keep it for view assertions.
        $original = $response->original;
        $response->setContent($html);
        $response->original = $original;

        if ($response->headers->has('Content-Length')) {
            $response->headers->set('Content-Length', (string) strlen($html));
        }
    }

    private function isHorizonPage(Request $request, HttpResponse $response): bool
    {
        $route = $request->route();
        $type = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'), 2)[0]));

        return $request->isMethod('GET')
            && $route instanceof Route
            && $route->getName() === self::ROUTE
            && $response->getStatusCode() === 200
            && $type === 'text/html'
            && ! $response->headers->has('Content-Encoding');
    }

    /**
     * The validated dashboard URL (http or https only), read at request time; null when unset or disabled.
     */
    private function grafanaUrl(): ?string
    {
        $config = $this->container->make(Config::class);

        return $config->enabled ? $config->grafanaUrl : null;
    }

    private function render(string $url): string
    {
        /** @var ViewFactory $views */
        $views = $this->container->make(ViewFactory::class);

        return $views->make(self::VIEW, ['url' => $url])->render();
    }

    private function report(Throwable $e): void
    {
        try {
            $this->container->make(FailureReporter::class)->report('menu-link', $e);
        } catch (Throwable) {
            // The link is optional: nothing may break Horizon's page.
        }
    }
}
