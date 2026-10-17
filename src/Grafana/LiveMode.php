<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

/**
 * How the Overview row gets its current values (owner decision 9, `--live=off|poll|stream`).
 *
 * Only `Off` is rendered until WP8D; it is what the pilot's dashboard did (every tile reads Prometheus).
 *
 * @internal
 */
enum LiveMode: string
{
    case Off = 'off';
    case Poll = 'poll';
    case Stream = 'stream';
}
