<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\HorizonUrl;

/*
| horizon_info{url,api_path}: the validated horizon_url override, or Horizon's address from app.url,
| horizon.domain and horizon.path, never with a trailing slash; the API path follows the routes' base path.
*/

/**
 * @param  array<string, mixed>  $config
 */
function horizonUrl(array $config): HorizonUrl
{
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    return new HorizonUrl(app('config'));
}

it('derives the URL from app.url and horizon.path', function (array $config, string $url): void {
    expect(horizonUrl([...['horizon.domain' => null], ...$config])->url())->toBe($url);
})->with([
    'default path' => [['app.url' => 'https://example.com', 'horizon.path' => 'horizon'], 'https://example.com/horizon'],
    'trailing slashes' => [['app.url' => 'https://example.com/', 'horizon.path' => '/ops/queues/'], 'https://example.com/ops/queues'],
    'app in a subdirectory' => [['app.url' => 'https://example.com/app', 'horizon.path' => 'horizon'], 'https://example.com/app/horizon'],
    'horizon at the root' => [['app.url' => 'https://example.com', 'horizon.path' => '/'], 'https://example.com'],
    'no app.url' => [['app.url' => null, 'horizon.path' => 'horizon'], '/horizon'],
    'neither' => [['app.url' => '', 'horizon.path' => ''], '/'],
]);

it('puts horizon.domain in place of the host and keeps the scheme and port of app.url', function (string $domain, string $url): void {
    expect(horizonUrl(['app.url' => 'http://example.com:8080/app', 'horizon.path' => 'horizon', 'horizon.domain' => $domain])->url())->toBe($url);
})->with([
    'host' => ['horizon.example.com', 'http://horizon.example.com:8080/horizon'],
    'host with its own port' => ['horizon.example.com:9000', 'http://horizon.example.com:9000/horizon'],
    'host with a scheme' => ['https://horizon.example.com/', 'https://horizon.example.com/horizon'],
]);

it('defaults to https for a domain when app.url has no scheme', function (): void {
    expect(horizonUrl(['app.url' => '', 'horizon.path' => 'horizon', 'horizon.domain' => 'horizon.example.com'])->url())->toBe('https://horizon.example.com/horizon');
});

it('prefers the validated horizon_url override without its trailing slash', function (): void {
    expect(horizonUrl(['app.url' => 'https://example.com', 'horizon-observability.horizon_url' => 'https://ops.example.com/horizon/'])->url())->toBe('https://ops.example.com/horizon')
        ->and(horizonUrl(['horizon-observability.horizon_url' => 'ftp://ops.example.com', 'horizon.path' => 'horizon', 'horizon.domain' => null])->url())->toBe('https://example.com/horizon');
});

it('uses the Config snapshot for the override', function (): void {
    config()->set('horizon-observability.horizon_url', 'https://snapshot.example.com');
    // The container's snapshot, resolved before the change, as in a long-running worker.
    $snapshot = app(Config::class);
    config()->set('horizon-observability.horizon_url', 'https://later.example.com');

    expect((new HorizonUrl(app('config'), $snapshot))->url())->toBe('https://snapshot.example.com')
        ->and(app(HorizonUrl::class)->url())->toBe('https://snapshot.example.com');
});

it('gives the API path of the routes', function (): void {
    expect(horizonUrl(['horizon.path' => 'horizon'])->apiPath())->toBe('/horizon/observability')
        ->and(horizonUrl(['horizon.path' => 'ops'])->apiPath())->toBe('/ops/observability')
        ->and(horizonUrl(['horizon-observability.path' => '/metrics/horizon/'])->apiPath())->toBe('/metrics/horizon');
});
