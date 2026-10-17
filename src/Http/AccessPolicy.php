<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use Illuminate\Http\Request;

/**
 * Access decision of the endpoints: the `HorizonObservability::auth()` callback, or tokens, the optional
 * `api_token`, the IP allow-list and the `local` default (Arch Q2.3).
 *
 * Stub (T02): denies everything. Owned by T03 (WP1).
 *
 * @internal
 */
final class AccessPolicy
{
    /**
     * @param  'metrics'|'api'  $endpoint  `metrics`, or `api` for the job lists and `/live`
     */
    public function decide(Request $request, string $endpoint): AccessDecision
    {
        return AccessDecision::Forbidden;
    }

    /**
     * Human-readable access mode for `about` and `check`, e.g. `token`, `token + IPs`, `callback`, `open (local, no
     * token)`, `locked`.
     */
    public function mode(): string
    {
        return 'locked';
    }
}
