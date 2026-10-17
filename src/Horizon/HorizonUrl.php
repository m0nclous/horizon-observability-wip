<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Horizon;

use Illuminate\Contracts\Config\Repository;

/**
 * Browser URL of Horizon for dashboard links (`horizon_info{url}`): the `horizon_url` override, or `app.url` with
 * the host replaced by `horizon.domain`, plus `/horizon.path` (Arch Q3).
 *
 * Stub (T02): override, else `app.url` plus the path; no domain handling. Owned by T05 (WP3).
 *
 * @internal
 */
final readonly class HorizonUrl
{
    public function __construct(private Repository $config) {}

    public function url(): string
    {
        $override = $this->config->get('horizon-observability.horizon_url');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $app = $this->config->get('app.url');
        $path = $this->config->get('horizon.path');

        return rtrim(is_string($app) ? $app : '', '/').'/'.trim(is_string($path) ? $path : 'horizon', '/');
    }
}
