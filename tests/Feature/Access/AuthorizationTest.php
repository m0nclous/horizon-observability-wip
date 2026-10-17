<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Http\Middleware\Authorize;
use DF\HorizonObservability\Http\Middleware\ForceJson;
use DF\HorizonObservability\Metrics\Exporter;
use DF\HorizonObservability\Tests\Support\Apps;
use DF\HorizonObservability\Tests\Unit\Metrics\Support\FakeCollector;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\HorizonServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/*
| C4 (Arch Q2.3, Appendix H): the auth matrix over HTTP. Locked by default outside local, 401 with a Bearer
| challenge for a missing or wrong token, rotation, the separate api_token, IPs alone and with tokens, the callback;
| 401/403 bodies as text on /metrics and JSON on the job lists and /live; nothing behind Authorize runs for a
| denied request, not even the configured throttle.
|
| Denials use the package routes. Allowed api requests use a test route with the same middleware, so the matrix does
| not depend on the job-list and live controllers (T06, T12).
*/

const AUTH_TOKEN = 'prometheus-token-0123456789abcdef';
const AUTH_ROTATED = 'rotated-token-0123456789abcdef';
const AUTH_API_TOKEN = 'grafana-api-token-0123456789abcdef';
const AUTH_API_ROUTE = '/_access/api';

dataset('package endpoints', [
    'metrics' => ['/horizon/observability/metrics', false],
    'pending jobs' => ['/horizon/observability/jobs/pending', true],
    'failed jobs' => ['/horizon/observability/jobs/failed', true],
    'delayed jobs' => ['/horizon/observability/jobs/delayed', true],
    'live' => ['/horizon/observability/live', true],
]);

/**
 * @param  array<string, mixed>  $config  horizon-observability.* values
 */
function authConfig(array $config, string $environment = 'production'): void
{
    app()['env'] = $environment;

    foreach ($config as $key => $value) {
        config()->set('horizon-observability.'.$key, $value);
    }

    app()->forgetInstance(Config::class);
}

/**
 * @return array<string, string>
 */
function authHeader(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

beforeEach(function (): void {
    // An api endpoint behind the package middleware whose controller needs nothing.
    Route::get(AUTH_API_ROUTE, static fn (): JsonResponse => new JsonResponse(['ok' => true]))
        ->middleware([ForceJson::class, Authorize::class.':api']);
});

it('is open in local when nothing is configured', function (): void {
    authConfig([], 'local');

    $this->get('/horizon/observability/metrics')->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    $this->get(AUTH_API_ROUTE)->assertOk()->assertExactJson(['ok' => true]);
});

it('locks every endpoint outside local when nothing is configured', function (string $url, bool $json, string $environment): void {
    authConfig([], $environment);

    $response = $this->get($url, authHeader(AUTH_TOKEN))->assertForbidden()->assertHeaderMissing('WWW-Authenticate');

    $json ? $response->assertExactJson(['message' => 'Forbidden.']) : expect($response->getContent())->toBe('Forbidden.');
})->with('package endpoints')->with(['production', 'testing', 'staging']);

it('answers 401 with a Bearer challenge when the token is missing or wrong', function (string $url, bool $json, ?string $authorization): void {
    authConfig(['token' => AUTH_TOKEN]);

    $response = $this->get($url, $authorization === null ? [] : ['Authorization' => $authorization])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="horizon-observability"');

    $json
        ? $response->assertExactJson(['message' => 'Unauthorized.'])->assertHeader('Content-Type', 'application/json')
        : expect($response->getContent())->toBe('Unauthorized.')->and($response->headers->get('Content-Type'))->toBe('text/plain; charset=utf-8');
})->with('package endpoints')->with([
    'no header' => [null],
    'wrong token' => ['Bearer wrong-token-0123456789abcdef'],
    'other scheme' => ['Basic '.base64_encode('prometheus:'.AUTH_TOKEN)],
    'empty bearer' => ['Bearer '],
]);

it('accepts a valid token', function (): void {
    authConfig(['token' => AUTH_TOKEN]);

    $this->get('/horizon/observability/metrics', authHeader(AUTH_TOKEN))->assertOk();
    $this->get(AUTH_API_ROUTE, authHeader(AUTH_TOKEN))->assertOk()->assertExactJson(['ok' => true]);
});

it('accepts every token of a rotated list', function (string $token): void {
    authConfig(['token' => AUTH_TOKEN.', '.AUTH_ROTATED]);

    $this->get('/horizon/observability/metrics', authHeader($token))->assertOk();
    $this->get(AUTH_API_ROUTE, authHeader($token))->assertOk();
})->with([AUTH_TOKEN, AUTH_ROTATED]);

it('keeps the Grafana api_token apart from the Prometheus token', function (): void {
    authConfig(['token' => AUTH_TOKEN, 'api_token' => AUTH_API_TOKEN]);

    $this->get('/horizon/observability/metrics', authHeader(AUTH_TOKEN))->assertOk();
    $this->get('/horizon/observability/metrics', authHeader(AUTH_API_TOKEN))->assertUnauthorized();
    $this->get(AUTH_API_ROUTE, authHeader(AUTH_API_TOKEN))->assertOk();
    $this->get(AUTH_API_ROUTE, authHeader(AUTH_TOKEN))->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
    $this->get('/horizon/observability/jobs/failed', authHeader(AUTH_TOKEN))->assertUnauthorized();
    $this->get('/horizon/observability/live', authHeader(AUTH_TOKEN))->assertUnauthorized();
});

it('locks /metrics when only the api_token is set', function (): void {
    authConfig(['api_token' => AUTH_API_TOKEN]);

    $this->get('/horizon/observability/metrics', authHeader(AUTH_API_TOKEN))->assertForbidden();
    $this->get(AUTH_API_ROUTE, authHeader(AUTH_API_TOKEN))->assertOk();
});

it('fails closed when no configured token is valid', function (): void {
    authConfig(['token' => 'too-short'], 'local');

    $this->get('/horizon/observability/metrics', authHeader('too-short'))->assertUnauthorized();
    $this->get('/horizon/observability/metrics')->assertUnauthorized();
});

it('checks the IP allow-list on its own', function (): void {
    authConfig(['allowed_ips' => '10.20.0.0/16, 192.168.1.10']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.3.4'])->get('/horizon/observability/metrics')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.10'])->get(AUTH_API_ROUTE)->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '10.21.3.4'])->get('/horizon/observability/metrics')->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => '10.21.3.4'])->get('/horizon/observability/jobs/pending')->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
});

it('requires both the IP and the token when both are configured', function (string $ip, ?string $token, int $status): void {
    authConfig(['allowed_ips' => '10.20.0.0/16', 'token' => AUTH_TOKEN]);

    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get('/horizon/observability/metrics', $token === null ? [] : authHeader($token))
        ->assertStatus($status);
})->with([
    'both pass' => ['10.20.3.4', AUTH_TOKEN, 200],
    'no token' => ['10.20.3.4', null, 401],
    'wrong token' => ['10.20.3.4', 'wrong-token-0123456789abcdef', 401],
    'wrong IP' => ['172.16.0.1', AUTH_TOKEN, 403],
    'both wrong' => ['172.16.0.1', null, 403],
]);

it('lets the auth callback alone decide', function (): void {
    authConfig(['token' => AUTH_TOKEN]);
    HorizonObservability::auth(static fn (Request $request): bool => $request->header('X-Client-Verify') === 'SUCCESS');

    $this->get('/horizon/observability/metrics', ['X-Client-Verify' => 'SUCCESS'])->assertOk();
    $this->get(AUTH_API_ROUTE, ['X-Client-Verify' => 'SUCCESS'])->assertOk();
    // The callback said no: 403, even with a valid token, and without a challenge.
    $this->get('/horizon/observability/metrics', authHeader(AUTH_TOKEN))->assertForbidden()->assertHeaderMissing('WWW-Authenticate');
    $this->get('/horizon/observability/jobs/pending', authHeader(AUTH_TOKEN))->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
});

it('never reads the token from the query string', function (string $parameter): void {
    authConfig(['token' => AUTH_TOKEN]);

    $this->get('/horizon/observability/metrics?'.$parameter.'='.AUTH_TOKEN)->assertUnauthorized();
    $this->get(AUTH_API_ROUTE.'?'.$parameter.'='.AUTH_TOKEN)->assertUnauthorized();
})->with(['token', 'api_token', 'access_token']);

it('answers JSON on /metrics only when the client asks for it', function (): void {
    authConfig(['token' => AUTH_TOKEN]);

    $this->getJson('/horizon/observability/metrics')->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
    $this->get('/horizon/observability/jobs/pending', ['Accept' => 'text/html'])->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
});

it('keeps the reason out of the body and never caches a denial', function (array $config, string $url, int $status, string $body): void {
    authConfig($config);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get($url)->assertStatus($status);

    expect($response->getContent())->toBe($body)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    'locked' => [[], '/horizon/observability/metrics', 403, 'Forbidden.'],
    'IP' => [['allowed_ips' => '10.0.0.0/8'], '/horizon/observability/metrics', 403, 'Forbidden.'],
    'token' => [['token' => AUTH_TOKEN], '/horizon/observability/metrics', 401, 'Unauthorized.'],
    'token, JSON' => [['token' => AUTH_TOKEN], '/horizon/observability/jobs/failed', 401, '{"message":"Unauthorized."}'],
]);

it('answers HEAD like GET', function (): void {
    authConfig(['token' => AUTH_TOKEN]);

    $this->call('HEAD', '/horizon/observability/metrics')->assertUnauthorized();
    $this->call('HEAD', '/horizon/observability/metrics', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.AUTH_TOKEN])->assertOk();
});

it('runs no collector for a denied request', function (): void {
    authConfig(['token' => AUTH_TOKEN]);
    $collector = new FakeCollector('horizon', static fn (): array => []);
    $this->app->instance(Exporter::class, new Exporter($this->app, null, ['horizon' => $collector]));

    $this->get('/horizon/observability/metrics')->assertUnauthorized();
    $this->get('/horizon/observability/metrics', authHeader('wrong-token-0123456789abcdef'))->assertUnauthorized();

    expect($collector->runs)->toBe(0);

    $this->get('/horizon/observability/metrics', authHeader(AUTH_TOKEN))->assertOk();

    expect($collector->runs)->toBe(1);
});

it('causes no cache write by the configured middleware when unauthenticated', function (): void {
    $result = Apps::inspect(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        [
            'cache.default' => 'array',
            'horizon-observability.token' => AUTH_TOKEN,
            'horizon-observability.middleware' => ['throttle:2,1'],
        ],
        static function (Application $app): array {
            $written = [];
            $app['events']->listen(KeyWritten::class, static function (KeyWritten $event) use (&$written): void {
                $written[] = $event->key;
            });
            $kernel = $app->make(Kernel::class);
            $send = static function (?string $token) use ($kernel): int {
                $server = ['REMOTE_ADDR' => '10.0.0.5'] + ($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
                $request = Request::create('/horizon/observability/metrics', 'GET', [], [], [], $server);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);

                return $response->getStatusCode();
            };

            $denied = [$send(null), $send('wrong-token-0123456789abcdef'), $send(null), $send(null)];
            $writtenWhileDenied = $written;
            $allowed = [$send(AUTH_TOKEN), $send(AUTH_TOKEN), $send(AUTH_TOKEN)];

            return [$denied, $writtenWhileDenied, $allowed, $written];
        },
    );

    [$denied, $writtenWhileDenied, $allowed, $written] = $result;

    // Four denied requests never reached the throttle (limit 2 per minute): no 429, no rate-limiter key. The only
    // cache writes are the markers of the throttled denial log lines (one per reason).
    expect($denied)->toBe([401, 401, 401, 401])
        ->and(array_filter($writtenWhileDenied, static fn (string $key): bool => ! str_starts_with($key, 'horizon-observability:log:')))->toBe([])
        ->and($writtenWhileDenied)->toHaveCount(2)
        // The throttle runs after Authorize for authorised requests: the third one is limited.
        ->and($allowed)->toBe([200, 200, Response::HTTP_TOO_MANY_REQUESTS])
        ->and(count($written))->toBeGreaterThan(count($writtenWhileDenied));
});
