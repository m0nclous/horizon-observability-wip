<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Пункт «Grafana» в меню Horizon сразу после Dashboard — ссылка на дашборд очередей (horizon-prometheus-grafana.grafana_url).
 *
 * Меню — часть шаблона horizon::layout, хуков для своих пунктов у Horizon нет. Пункт вставляется
 * в готовую страницу, а не копией шаблона: копия расходилась бы с Horizon после обновлений.
 * Если Horizon поменяет разметку меню, пункт просто пропадёт.
 */
class AddGrafanaLinkToHorizon
{
    private const SIDEBAR = 'class="col-2 sidebar"';
    private const ITEM_END = '</li>';

    public function handle(Request $request, Closure $next): BaseResponse
    {
        $response = $next($request);
        $url = config('horizon-prometheus-grafana.grafana_url');

        // JSON API Horizon — JsonResponse, сюда попадает только страница
        if (!$url || !$response instanceof Response) {
            return $response;
        }

        $content = (string) $response->getContent();
        $sidebar = strpos($content, self::SIDEBAR);
        // Конец первого пункта меню — Dashboard
        $firstItemEnd = $sidebar === false ? false : strpos($content, self::ITEM_END, $sidebar);

        if ($firstItemEnd === false) {
            return $response;
        }

        $item = view('horizon-prometheus-grafana::grafana_link', ['url' => $url])->render();

        return $response->setContent(
            substr_replace($content, $item, $firstItemEnd + strlen(self::ITEM_END), 0),
        );
    }
}
