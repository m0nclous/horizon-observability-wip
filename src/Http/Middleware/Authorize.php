<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `Authorize:metrics` / `Authorize:api`: 401 or 403 before any Redis or DB work (Arch Q2.3).
 *
 * Stub (T02): always 403, plain text or JSON; touches nothing. Owned by T03 (WP1).
 *
 * @internal
 */
final class Authorize
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $endpoint = 'metrics'): Response
    {
        return $request->expectsJson()
            ? new JsonResponse(['message' => 'Forbidden.'], 403)
            : new Response('Forbidden.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
