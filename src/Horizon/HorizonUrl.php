<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Horizon;

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Http\RoutePath;
use Illuminate\Contracts\Config\Repository;

/**
 * The links of `horizon_info` for the dashboard: the browser URL of Horizon and the job-list API path.
 *
 * The URL is the validated `horizon_url` override, or Horizon's address as Laravel's URL generator builds it for
 * Horizon's routes: `app.url` plus `/<horizon.path>`, and with `horizon.domain` set, the scheme and port of `app.url`
 * with that domain as the host. Without a domain the path of `app.url` is kept (an app served from a
 * subdirectory). There is never a trailing slash, because the dashboard appends `/jobs/...` and `/batches/...`.
 *
 * @internal
 */
final readonly class HorizonUrl
{
    /**
     * @param  Config|null  $settings  the validated snapshot; null = built from $config on use
     */
    public function __construct(private Repository $config, private ?Config $settings = null) {}

    public function url(): string
    {
        $override = ($this->settings ?? Config::fromRepository($this->config))->horizonUrl;

        if ($override !== null) {
            $trimmed = rtrim($override, '/');

            return $trimmed === '' ? $override : $trimmed;
        }

        $path = $this->config->get('horizon.path');
        $path = trim(is_string($path) ? $path : 'horizon', '/');
        $base = $this->base();
        $url = $path === '' ? $base : $base.'/'.$path;

        return $url === '' ? '/' : $url;
    }

    /**
     * The path Grafana appends to its Infinity datasource URL for the job lists and `/live`, e.g.
     * `/horizon/observability` (`horizon_info{api_path}`).
     */
    public function apiPath(): string
    {
        return RoutePath::apiPath($this->config);
    }

    /**
     * `app.url` without a trailing slash, or `<scheme>://<horizon.domain>[:<port>]` when Horizon has a domain.
     */
    private function base(): string
    {
        $app = $this->config->get('app.url');
        $app = rtrim(is_string($app) ? trim($app) : '', '/');
        $domain = $this->config->get('horizon.domain');
        $domain = is_string($domain) ? trim($domain, " \t\n\r\0\x0B/") : '';

        if ($domain === '') {
            return $app;
        }

        if (str_contains($domain, '://')) {
            return $domain;
        }

        $parts = parse_url($app);
        $scheme = is_array($parts) && isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $port = is_array($parts) && isset($parts['port']) && ! str_contains($domain, ':') ? ':'.$parts['port'] : '';

        return $scheme.'://'.$domain.$port;
    }
}
