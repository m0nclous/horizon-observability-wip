<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\Http\AccessDecision;
use DF\HorizonObservability\Http\AccessPolicy;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use Illuminate\Http\Request;

/*
| Arch Q2.3 and 8.4: the full decision table of the access policy and its mode() for about and check (C4).
*/

const ACCESS_POLICY_TOKEN = 'metrics-token-0123456789abcdef';
const ACCESS_POLICY_ROTATED = 'rotated-token-0123456789abcdef';
const ACCESS_POLICY_API_TOKEN = 'grafana-token-0123456789abcdef';

/**
 * @param  array<string, mixed>  $config  horizon-observability.* values
 */
function accessPolicyFor(array $config = [], string $environment = 'production'): AccessPolicy
{
    app()['env'] = $environment;

    foreach ($config as $key => $value) {
        config()->set('horizon-observability.'.$key, $value);
    }

    return new AccessPolicy(Config::fromRepository(config()), app(), app(FailureReporter::class));
}

function accessPolicyRequest(?string $authorization = null, string $ip = '10.0.0.5', string $uri = '/horizon/observability/metrics'): Request
{
    $server = ['REMOTE_ADDR' => $ip];

    if ($authorization !== null) {
        $server['HTTP_AUTHORIZATION'] = $authorization;
    }

    return Request::create($uri, 'GET', [], [], [], $server);
}

function accessPolicyBearer(string $token): string
{
    return 'Bearer '.$token;
}

it('decides by the built-in policy', function (array $config, string $environment, ?string $authorization, string $ip, AccessDecision $metrics, AccessDecision $api): void {
    $policy = accessPolicyFor($config, $environment);

    expect($policy->decide(accessPolicyRequest($authorization, $ip), 'metrics'))->toBe($metrics)
        ->and($policy->decide(accessPolicyRequest($authorization, $ip, '/horizon/observability/jobs/pending'), 'api'))->toBe($api);
})->with([
    // Neither tokens nor IPs: open in local only, like Horizon's default gate.
    'nothing configured, local' => [[], 'local', null, '10.0.0.5', AccessDecision::Allowed, AccessDecision::Allowed],
    'nothing configured, production' => [[], 'production', null, '10.0.0.5', AccessDecision::Forbidden, AccessDecision::Forbidden],
    'nothing configured, testing' => [[], 'testing', null, '10.0.0.5', AccessDecision::Forbidden, AccessDecision::Forbidden],
    'nothing configured, staging with a token sent' => [[], 'staging', accessPolicyBearer(ACCESS_POLICY_TOKEN), '10.0.0.5', AccessDecision::Forbidden, AccessDecision::Forbidden],

    // Tokens.
    'token, none sent' => [['token' => ACCESS_POLICY_TOKEN], 'production', null, '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Unauthenticated],
    'token, wrong one' => [['token' => ACCESS_POLICY_TOKEN], 'production', accessPolicyBearer('wrong-token-0123456789abcdef'), '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Unauthenticated],
    'token, a prefix of it' => [['token' => ACCESS_POLICY_TOKEN], 'production', accessPolicyBearer(substr(ACCESS_POLICY_TOKEN, 0, 20)), '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Unauthenticated],
    'token, valid' => [['token' => ACCESS_POLICY_TOKEN], 'production', accessPolicyBearer(ACCESS_POLICY_TOKEN), '10.0.0.5', AccessDecision::Allowed, AccessDecision::Allowed],
    'token, lowercase scheme and padding' => [['token' => ACCESS_POLICY_TOKEN], 'production', 'bearer   '.ACCESS_POLICY_TOKEN.' ', '10.0.0.5', AccessDecision::Allowed, AccessDecision::Allowed],
    'token, other scheme' => [['token' => ACCESS_POLICY_TOKEN], 'production', 'Basic '.base64_encode('user:'.ACCESS_POLICY_TOKEN), '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Unauthenticated],
    'token, scheme without a token' => [['token' => ACCESS_POLICY_TOKEN], 'production', 'Bearer ', '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Unauthenticated],
    'token, required in local too' => [['token' => ACCESS_POLICY_TOKEN], 'local', null, '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Unauthenticated],
    'token, rotated list, old one' => [['token' => ACCESS_POLICY_TOKEN.', '.ACCESS_POLICY_ROTATED], 'production', accessPolicyBearer(ACCESS_POLICY_TOKEN), '10.0.0.5', AccessDecision::Allowed, AccessDecision::Allowed],
    'token, rotated list, new one' => [['token' => ACCESS_POLICY_TOKEN.','.ACCESS_POLICY_ROTATED], 'production', accessPolicyBearer(ACCESS_POLICY_ROTATED), '10.0.0.5', AccessDecision::Allowed, AccessDecision::Allowed],
    'token, rotated list as an array' => [['token' => [ACCESS_POLICY_TOKEN, ACCESS_POLICY_ROTATED]], 'production', accessPolicyBearer(ACCESS_POLICY_ROTATED), '10.0.0.5', AccessDecision::Allowed, AccessDecision::Allowed],
    'token, the list itself' => [['token' => ACCESS_POLICY_TOKEN.','.ACCESS_POLICY_ROTATED], 'production', accessPolicyBearer(ACCESS_POLICY_TOKEN.','.ACCESS_POLICY_ROTATED), '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Unauthenticated],

    // Fail closed: a token setting without a valid entry lets nothing pass, even the short token itself.
    'short token only' => [['token' => 'short-token'], 'local', accessPolicyBearer('short-token'), '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Unauthenticated],
    'short token next to a valid one' => [['token' => 'short-token,'.ACCESS_POLICY_TOKEN], 'production', accessPolicyBearer('short-token'), '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Unauthenticated],

    // api_token: Grafana's separate secret for the job lists and /live.
    'api_token, main token' => [['token' => ACCESS_POLICY_TOKEN, 'api_token' => ACCESS_POLICY_API_TOKEN], 'production', accessPolicyBearer(ACCESS_POLICY_TOKEN), '10.0.0.5', AccessDecision::Allowed, AccessDecision::Unauthenticated],
    'api_token, api token' => [['token' => ACCESS_POLICY_TOKEN, 'api_token' => ACCESS_POLICY_API_TOKEN], 'production', accessPolicyBearer(ACCESS_POLICY_API_TOKEN), '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Allowed],
    'api_token only, production' => [['api_token' => ACCESS_POLICY_API_TOKEN], 'production', accessPolicyBearer(ACCESS_POLICY_API_TOKEN), '10.0.0.5', AccessDecision::Forbidden, AccessDecision::Allowed],
    'api_token only, local' => [['api_token' => ACCESS_POLICY_API_TOKEN], 'local', null, '10.0.0.5', AccessDecision::Allowed, AccessDecision::Unauthenticated],
    'api_token without a valid entry' => [['token' => ACCESS_POLICY_TOKEN, 'api_token' => 'short'], 'production', accessPolicyBearer(ACCESS_POLICY_TOKEN), '10.0.0.5', AccessDecision::Allowed, AccessDecision::Unauthenticated],

    // IP allow-list, alone and combined with tokens (both must pass; the IP is checked first).
    'IPs only, listed' => [['allowed_ips' => '10.0.0.5'], 'production', null, '10.0.0.5', AccessDecision::Allowed, AccessDecision::Allowed],
    'IPs only, in a CIDR' => [['allowed_ips' => '192.168.0.1, 10.0.0.0/8'], 'production', null, '10.0.0.5', AccessDecision::Allowed, AccessDecision::Allowed],
    'IPs only, IPv6 CIDR' => [['allowed_ips' => 'fd00::/8'], 'production', null, 'fd00::1', AccessDecision::Allowed, AccessDecision::Allowed],
    'IPs only, not listed' => [['allowed_ips' => '10.0.0.0/24'], 'production', null, '10.0.1.5', AccessDecision::Forbidden, AccessDecision::Forbidden],
    'IPs only, not listed, local' => [['allowed_ips' => '10.0.0.0/24'], 'local', null, '127.0.0.1', AccessDecision::Forbidden, AccessDecision::Forbidden],
    'IPs without a valid entry' => [['allowed_ips' => 'not-an-ip, 10.0.0.0/99'], 'local', null, '10.0.0.5', AccessDecision::Forbidden, AccessDecision::Forbidden],
    'IPs and token, both pass' => [['allowed_ips' => '10.0.0.0/8', 'token' => ACCESS_POLICY_TOKEN], 'production', accessPolicyBearer(ACCESS_POLICY_TOKEN), '10.0.0.5', AccessDecision::Allowed, AccessDecision::Allowed],
    'IPs and token, wrong token' => [['allowed_ips' => '10.0.0.0/8', 'token' => ACCESS_POLICY_TOKEN], 'production', accessPolicyBearer('wrong-token-0123456789abcdef'), '10.0.0.5', AccessDecision::Unauthenticated, AccessDecision::Unauthenticated],
    'IPs and token, wrong IP' => [['allowed_ips' => '10.0.0.0/8', 'token' => ACCESS_POLICY_TOKEN], 'production', accessPolicyBearer(ACCESS_POLICY_TOKEN), '172.16.0.1', AccessDecision::Forbidden, AccessDecision::Forbidden],
    'IPs and token, both wrong' => [['allowed_ips' => '10.0.0.0/8', 'token' => ACCESS_POLICY_TOKEN], 'production', null, '172.16.0.1', AccessDecision::Forbidden, AccessDecision::Forbidden],
]);

it('never reads tokens from the query string', function (): void {
    $policy = accessPolicyFor(['token' => ACCESS_POLICY_TOKEN]);

    foreach (['token', 'api_token', 'access_token', 'bearer'] as $parameter) {
        $request = Request::create('/horizon/observability/metrics?'.$parameter.'='.ACCESS_POLICY_TOKEN, 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.5']);

        expect($policy->decide($request, 'metrics'))->toBe(AccessDecision::Unauthenticated);
    }
});

it('lets the auth callback alone decide', function (mixed $result, AccessDecision $expected): void {
    $seen = null;
    HorizonObservability::auth(static function (Request $request) use (&$seen, $result): mixed {
        $seen = $request;

        return $result;
    });
    // A valid token and IP do not matter, and a locked production setup does not either.
    $policy = accessPolicyFor(['token' => ACCESS_POLICY_TOKEN, 'allowed_ips' => '10.0.0.0/8']);
    $request = accessPolicyRequest(accessPolicyBearer(ACCESS_POLICY_TOKEN));

    expect($policy->decide($request, 'metrics'))->toBe($expected)
        ->and($policy->decide($request, 'api'))->toBe($expected)
        ->and($seen)->toBe($request);
})->with([
    'true' => [true, AccessDecision::Allowed],
    'false' => [false, AccessDecision::Forbidden],
    'truthy but not true' => [1, AccessDecision::Forbidden],
    'null' => [null, AccessDecision::Forbidden],
]);

it('allows through the callback where the built-in policy would lock', function (): void {
    HorizonObservability::auth(static fn (Request $request): bool => $request->header('X-Client-Verify') === 'SUCCESS');
    $policy = accessPolicyFor();

    $verified = accessPolicyRequest();
    $verified->headers->set('X-Client-Verify', 'SUCCESS');

    expect($policy->decide($verified, 'metrics'))->toBe(AccessDecision::Allowed)
        ->and($policy->decide(accessPolicyRequest(), 'metrics'))->toBe(AccessDecision::Forbidden);
});

it('denies when the callback throws, and logs it', function (): void {
    $log = new LogRecorder;
    $this->app->instance('log', $log);
    HorizonObservability::auth(static fn (): bool => throw new RuntimeException('gate exploded'));

    expect(accessPolicyFor()->decide(accessPolicyRequest(), 'metrics'))->toBe(AccessDecision::Forbidden)
        ->and($log->messages('warning'))->toBe(['horizon-observability: access.callback failed: RuntimeException: gate exploded']);
});

it('denies an unknown endpoint', function (): void {
    expect(accessPolicyFor([], 'local')->decide(accessPolicyRequest(), 'jobs'))->toBe(AccessDecision::Forbidden)
        ->and(accessPolicyFor(['token' => ACCESS_POLICY_TOKEN])->decide(accessPolicyRequest(accessPolicyBearer(ACCESS_POLICY_TOKEN)), ''))->toBe(AccessDecision::Forbidden);
});

it('logs one throttled warning per endpoint and reason, without the token', function (): void {
    $log = new LogRecorder;
    $this->app->instance('log', $log);
    $policy = accessPolicyFor(['token' => ACCESS_POLICY_TOKEN, 'api_token' => ACCESS_POLICY_API_TOKEN]);

    foreach (range(1, 3) as $ignored) {
        $policy->decide(accessPolicyRequest(), 'metrics');
        $policy->decide(accessPolicyRequest(accessPolicyBearer('wrong-token-0123456789abcdef')), 'metrics');
        $policy->decide(accessPolicyRequest(accessPolicyBearer(ACCESS_POLICY_TOKEN)), 'api');
        $policy->decide(accessPolicyRequest(accessPolicyBearer(ACCESS_POLICY_TOKEN)), 'metrics');
    }

    expect($log->messages('warning'))->toBe([
        'horizon-observability: metrics request denied (401): the request has no "Authorization: Bearer" token; token is required.',
        'horizon-observability: metrics request denied (401): the bearer token matches none of the valid tokens in token.',
        'horizon-observability: api request denied (401): the bearer token matches none of the valid tokens in api_token.',
    ])
        ->and($log->records[0]['context'])->toBe(['ip' => '10.0.0.5', 'path' => '/horizon/observability/metrics'])
        ->and(json_encode($log->records))->not->toContain(ACCESS_POLICY_TOKEN)
        ->and(json_encode($log->records))->not->toContain('wrong-token');
});

it('names the reason of every denial in the log', function (array $config, string $environment, ?Closure $callback, string $ip, string $message): void {
    $log = new LogRecorder;
    $this->app->instance('log', $log);

    if ($callback instanceof Closure) {
        HorizonObservability::auth($callback);
    }

    accessPolicyFor($config, $environment)->decide(accessPolicyRequest(null, $ip), 'metrics');

    expect($log->messages('warning'))->toBe(['horizon-observability: metrics request denied (403): '.$message.'.']);
})->with([
    'locked' => [[], 'production', null, '10.0.0.5', 'no token, IP allow-list or auth callback is configured outside the local environment; set HORIZON_OBSERVABILITY_TOKEN'],
    'ip' => [['allowed_ips' => '192.168.0.0/16'], 'production', null, '10.0.0.5', 'the client IP is not in allowed_ips; behind a proxy, check the TrustProxies configuration'],
    'callback' => [[], 'production', static fn (): bool => false, '10.0.0.5', 'the HorizonObservability::auth() callback did not return true'],
]);

it('describes the access mode for about and check', function (array $config, string $environment, bool $callback, string $mode): void {
    if ($callback) {
        HorizonObservability::auth(static fn (): bool => true);
    }

    expect(accessPolicyFor($config, $environment)->mode())->toBe($mode);
})->with([
    'nothing, production' => [[], 'production', false, 'locked (set HORIZON_OBSERVABILITY_TOKEN)'],
    'nothing, local' => [[], 'local', false, 'open (local, no token)'],
    'token' => [['token' => ACCESS_POLICY_TOKEN], 'production', false, 'token'],
    'rotated tokens' => [['token' => ACCESS_POLICY_TOKEN.','.ACCESS_POLICY_ROTATED], 'production', false, 'token'],
    'token and IPs' => [['token' => ACCESS_POLICY_TOKEN, 'allowed_ips' => '10.0.0.0/8'], 'production', false, 'token + IPs'],
    'IPs only' => [['allowed_ips' => '10.0.0.0/8'], 'production', false, 'IPs'],
    'separate api_token' => [['token' => ACCESS_POLICY_TOKEN, 'api_token' => ACCESS_POLICY_API_TOKEN], 'production', false, 'metrics: token; api: api_token'],
    'api_token only' => [['api_token' => ACCESS_POLICY_API_TOKEN], 'production', false, 'metrics: locked (set HORIZON_OBSERVABILITY_TOKEN); api: api_token'],
    'api_token only, local' => [['api_token' => ACCESS_POLICY_API_TOKEN], 'local', false, 'metrics: open (local, no token); api: api_token'],
    'no valid token' => [['token' => 'short'], 'production', false, 'token (none valid: nothing passes)'],
    'no valid IP' => [['allowed_ips' => 'nope'], 'local', false, 'IPs (none valid: nothing passes)'],
    'callback' => [['token' => ACCESS_POLICY_TOKEN], 'production', true, 'callback'],
]);

it('is what the about section shows', function (): void {
    expect($this->app->make(AccessPolicy::class)->mode())->toBe('locked (set HORIZON_OBSERVABILITY_TOKEN)');
});
