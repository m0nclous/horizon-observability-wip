<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the JSON endpoints (the job lists and `/live`) answer JSON whatever the client sends: it sets
 * `Accept: application/json` before {@see Authorize} and the controller run, so 401, 403, 422 and errors are JSON
 * too (Arch Q2.1). It runs first on those routes, so a browser can never be redirected to a login page.
 *
 * @internal
 */
final class ForceJson
{
    public const ACCEPT = 'application/json';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', self::ACCEPT);

        return $next($request);
    }
}
