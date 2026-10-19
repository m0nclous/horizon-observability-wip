<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Support;

use DF\HorizonObservability\Http\RoutePath;
use Illuminate\Contracts\Config\Repository;

/**
 * Where the package endpoints answer, for the `about` section, `horizon-observability:check` and
 * `horizon-observability:install` (Arch Q2.1): `app.url` plus the base path, or with `horizon-observability.domain`
 * that host. The routes are bound to the domain, so on `app.url`'s host they get a 404 or Horizon's catch-all.
 *
 * A domain URL takes the scheme and port of `app.url`, as Laravel's URL generator builds the URL of a domain route
 * (a scheme written in the domain is dropped, as `Route::getDomain()` drops it). A domain with route parameters
 * (`{tenant}.example.com`) names no single host and is ignored here.
 *
 * @internal
 */
final class EndpointUrl
{
    /**
     * The host the endpoints are bound to, or null when they answer on any host (or the domain has parameters).
     */
    public static function domain(Repository $config): ?string
    {
        $domain = $config->get('horizon-observability.domain');
        $domain = is_string($domain) ? (string) preg_replace('#^https?://#i', '', trim($domain)) : '';
        $domain = rtrim($domain, '/');

        return $domain === '' || preg_match('/[{}\s\/]/', $domain) === 1 ? null : $domain;
    }

    /**
     * The application root the endpoints answer on, without a trailing slash: `app.url` (http://localhost when it is
     * not set), or `<scheme of app.url>://<domain>[:<port of app.url>]` with a domain.
     */
    public static function root(Repository $config): string
    {
        $app = $config->get('app.url');
        $app = rtrim(is_string($app) ? trim($app) : '', '/');
        $app = $app === '' ? 'http://localhost' : $app;
        $domain = self::domain($config);

        if ($domain === null) {
            return $app;
        }

        $parts = parse_url($app);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : 'http';
        $port = is_array($parts) && isset($parts['port']) && ! str_contains($domain, ':') ? ':'.$parts['port'] : '';

        return $scheme.'://'.$domain.$port;
    }

    /**
     * The URL of an endpoint, e.g. `/metrics`, or of the base path without `$path`: {@see self::root()} plus the path,
     * as the install snippets scrape it.
     */
    public static function to(Repository $config, string $path = ''): string
    {
        return self::root($config).RoutePath::apiPath($config).$path;
    }
}
