<?php

declare(strict_types=1);

use DF\HorizonObservability\Install\AppUrl;

/*
| The app URL as Prometheus and Grafana reach it (horizon-observability:install).
*/

it('splits an app URL for a scrape job', function (string $url, string $target, string $scheme, string $path, string $string): void {
    $app = AppUrl::parse($url);

    expect($app->target())->toBe($target)
        ->and($app->scheme)->toBe($scheme)
        ->and($app->path)->toBe($path)
        ->and($app->toString())->toBe($string);
})->with([
    ['http://localhost', 'localhost:80', 'http', '', 'http://localhost'],
    ['https://shop.example.com/', 'shop.example.com:443', 'https', '', 'https://shop.example.com'],
    ['HTTP://app:8080/sub/dir/', 'app:8080', 'http', '/sub/dir', 'http://app:8080/sub/dir'],
    ['http://[::1]:8000', '[::1]:8000', 'http', '', 'http://[::1]:8000'],
]);

it('points a local app at the Docker host gateway', function (string $url, string $docker): void {
    expect(AppUrl::parse($url)->forDocker()->toString())->toBe($docker);
})->with([
    ['http://localhost', 'http://host.docker.internal:8000'],
    ['http://127.0.0.1:8080', 'http://host.docker.internal:8080'],
    ['http://0.0.0.0', 'http://host.docker.internal:8000'],
    ['https://localhost/app', 'https://host.docker.internal:8000/app'],
    ['http://myapp.test', 'http://myapp.test'],
]);

it('rejects what is not an http(s) URL', function (string $url): void {
    expect(static fn (): AppUrl => AppUrl::parse($url))->toThrow(InvalidArgumentException::class);
})->with(['', 'localhost:8000', 'ftp://host', 'http://', '/path']);
