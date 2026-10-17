<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

/**
 * The Grafana major version a render targets (owner decision 4: 12.3+ and 13 are supported).
 *
 * The legacy render does not depend on it. WP8B adds version-specific output only where probes on 12.4.13 and
 * 13.2.4 show that one version needs it.
 *
 * @internal
 */
enum GrafanaVersion: string
{
    case Grafana12 = '12';
    case Grafana13 = '13';
}
