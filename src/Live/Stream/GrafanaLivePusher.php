<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live\Stream;

use SensitiveParameter;

/**
 * `POST {grafana}/api/live/push/{id}` with a service-account token, 2 s timeout and 1 s connect timeout
 * (Addendum R4.6).
 *
 * Stub (T02): pushes nothing. Owned by T12 (WP-L2).
 *
 * @internal
 */
final class GrafanaLivePusher
{
    public function push(string $baseUrl, string $streamId, #[SensitiveParameter] string $token, string $body): PushResult
    {
        return new PushResult(false, null, 'The stream push is not implemented yet.', 0.0);
    }
}
