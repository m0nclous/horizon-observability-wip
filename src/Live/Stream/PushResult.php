<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live\Stream;

/**
 * Outcome of one Grafana Live push (Addendum R4.6).
 *
 * @internal
 */
final readonly class PushResult
{
    /**
     * @param  int|null  $status  HTTP status; null when no response arrived (connect error, timeout)
     * @param  string|null  $error  short reason on failure, never the token
     */
    public function __construct(public bool $ok, public ?int $status, public ?string $error, public float $seconds) {}
}
