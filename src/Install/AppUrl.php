<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Install;

use InvalidArgumentException;

/**
 * An http(s) URL of the application as a scraper reaches it, split for the Prometheus scrape job: `scheme`, the
 * `host:port` target and the path of a subdirectory the app is served from.
 *
 * @internal
 */
final readonly class AppUrl
{
    /**
     * Hosts that mean "this machine": from a container the app is `host.docker.internal` instead.
     */
    public const LOCAL_HOSTS = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];

    public function __construct(public string $scheme, public string $host, public ?int $port, public string $path) {}

    /**
     * @throws InvalidArgumentException unless it is an http(s) URL with a host
     */
    public static function parse(string $url): self
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true) || $parts['host'] === '') {
            throw new InvalidArgumentException(sprintf('[%s] is not an http(s) URL.', $url));
        }

        return new self(strtolower($parts['scheme']), $parts['host'], $parts['port'] ?? null, rtrim($parts['path'] ?? '', '/'));
    }

    /**
     * The URL Docker containers reach the app at: a local host becomes `host.docker.internal`, with the port of the
     * URL, else `$defaultPort` (the port of `php artisan serve`); any other host is kept.
     */
    public function forDocker(int $defaultPort = 8000): self
    {
        if (! in_array(strtolower($this->host), self::LOCAL_HOSTS, true)) {
            return $this;
        }

        return new self($this->scheme, 'host.docker.internal', $this->port ?? $defaultPort, $this->path);
    }

    public function isLocal(): bool
    {
        return in_array(strtolower($this->host), self::LOCAL_HOSTS, true);
    }

    /**
     * `host:port` with the scheme's default port when there is none.
     */
    public function target(): string
    {
        $port = $this->port ?? ($this->scheme === 'https' ? 443 : 80);
        $host = str_contains($this->host, ':') && ! str_starts_with($this->host, '[') ? '['.$this->host.']' : $this->host;

        return $host.':'.$port;
    }

    /**
     * The URL without a trailing slash, e.g. `http://host.docker.internal:8000`.
     */
    public function toString(): string
    {
        $host = str_contains($this->host, ':') && ! str_starts_with($this->host, '[') ? '['.$this->host.']' : $this->host;

        return $this->scheme.'://'.$host.($this->port === null ? '' : ':'.$this->port).$this->path;
    }
}
