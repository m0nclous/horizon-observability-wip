<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use DF\HorizonObservability\Config\Config;
use Illuminate\Contracts\Config\Repository;

/**
 * Base path of the package endpoints (Arch Q2.1).
 *
 * Runs at boot, so it reads the raw config with safe casts and never throws. `horizon-observability.path` wins when
 * it is usable; otherwise the base is Horizon's path plus `/observability`, so the package never registers a bare
 * `/observability` or `/metrics`.
 *
 * @internal
 */
final class RoutePath
{
    /**
     * Segment appended to Horizon's path for the derived default.
     */
    public const SEGMENT = 'observability';

    /**
     * Base path without leading or trailing slashes, e.g. `horizon/observability` or `metrics/horizon`.
     */
    public static function base(Repository $config): string
    {
        $custom = self::normalize($config->get('horizon-observability.path'));

        if ($custom !== null) {
            return $custom;
        }

        $horizon = $config->get('horizon.path');
        $horizon = is_string($horizon) ? trim($horizon, '/') : '';

        return ($horizon === '' ? 'horizon' : $horizon).'/'.self::SEGMENT;
    }

    /**
     * Base path as the API path Grafana appends to its datasource URL (`horizon_info{api_path}`), e.g. `/horizon/observability`.
     */
    public static function apiPath(Repository $config): string
    {
        return '/'.self::base($config);
    }

    /**
     * A configured `path` trimmed of slashes, or null when it is unset, empty or unusable (contains `{`, `}`, `..`
     * or whitespace). {@see Config} reports an unusable value as a config problem.
     */
    public static function normalize(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path, '/');

        if ($path === '' || preg_match('/[{}\s]|\.\./', $path) === 1) {
            return null;
        }

        return $path;
    }
}
