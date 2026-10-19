<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http\Middleware;

use Closure;
use DF\HorizonObservability\Http\AccessDecision;
use DF\HorizonObservability\Http\AccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `Authorize:metrics` / `Authorize:api`: the {@see AccessPolicy} decides before any
 * Redis or DB work, and before the configured middleware (e.g. a throttle), which runs after it.
 *
 * - 401 `Unauthorized.` with `WWW-Authenticate: Bearer realm="horizon-observability"` when a token is required and
 *   missing or wrong;
 * - 403 `Forbidden.` for an IP mismatch, a locked endpoint or a callback that said no.
 *
 * Bodies are plain text, or `{"message": "..."}` when the request expects JSON (the job lists and `/live`, where
 * {@see ForceJson} runs first). They never carry the reason; denials are never cached.
 *
 * @internal
 */
final readonly class Authorize
{
    public const CHALLENGE = 'Bearer realm="horizon-observability"';

    public function __construct(private AccessPolicy $policy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $endpoint = 'metrics'): Response
    {
        return match ($this->policy->decide($request, $endpoint)) {
            AccessDecision::Allowed => $next($request),
            AccessDecision::Unauthenticated => $this->deny($request, 401, 'Unauthorized.', ['WWW-Authenticate' => self::CHALLENGE]),
            AccessDecision::Forbidden => $this->deny($request, 403, 'Forbidden.'),
        };
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function deny(Request $request, int $status, string $message, array $headers = []): Response
    {
        $headers['Cache-Control'] = 'no-store';

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $message], $status, $headers);
        }

        return new Response($message, $status, [...$headers, 'Content-Type' => 'text/plain; charset=utf-8']);
    }
}
