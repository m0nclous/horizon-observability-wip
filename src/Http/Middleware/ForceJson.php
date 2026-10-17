<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the JSON endpoints answer JSON whatever the client sends (`Accept: application/json`), so errors, 401/403
 * and 422 are JSON too (Arch Q2.1).
 *
 * Owned by T03 (WP1).
 *
 * @internal
 */
final class ForceJson
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
