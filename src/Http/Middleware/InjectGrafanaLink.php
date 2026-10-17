<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds a "Grafana" link right after "Dashboard" in Horizon's sidebar (`horizon.index` HTML only). The provider
 * appends it to `horizon.middleware` when `grafana.url` is set (Arch Q7, C16).
 *
 * Stub (T02): passes the response through. Owned by T09 (WP7).
 *
 * @internal
 */
final class InjectGrafanaLink
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
