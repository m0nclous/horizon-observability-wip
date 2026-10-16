<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Списки джобов читает Grafana, а не браузер: ошибки (доступ, валидация) — тоже JSON, а не HTML-страница
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
