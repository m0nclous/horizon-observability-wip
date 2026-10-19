<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

/**
 * Result of the access policy: 200, 401 with `WWW-Authenticate: Bearer`, or 403.
 *
 * @internal
 */
enum AccessDecision
{
    case Allowed;

    /**
     * A token is required and the request has none or a wrong one (401).
     */
    case Unauthenticated;

    /**
     * IP mismatch, locked outside `local`, or the auth callback said no (403).
     */
    case Forbidden;
}
