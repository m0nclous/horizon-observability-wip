<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use Closure;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;
use WeakMap;

/**
 * Access decision of the endpoints (Arch Q2.3, C4). It runs before any Redis or DB work.
 *
 * 1. **Callback.** When the app called `HorizonObservability::auth()`, the callback alone decides: `true` allows,
 *    anything else (including a throw) answers 403.
 * 2. **Built-in policy** otherwise, failing closed:
 *    - **Tokens.** `token` (a comma-separated list, for rotation) on both endpoints; `api_token`, when set, replaces
 *      it on the job lists and `/live` (endpoint `api`). Read from `Authorization: Bearer ...` only, never from the
 *      query string, and compared with `hash_equals()` against every configured token. A token setting whose every
 *      entry was invalid (e.g. shorter than 16 characters) still requires a token, so nothing passes
 *      ({@see Config::requiresToken()}). No token or a wrong one: 401.
 *    - **IP allow-list.** `allowed_ips` (IPs or CIDRs) against `$request->ip()`, so it relies on correct
 *      TrustProxies; checked first, a mismatch answers 403 whatever the token. A list without a valid entry lets
 *      nothing pass.
 *    - With tokens and IPs, both must pass. With neither: allowed only in the `local` environment, like Horizon's
 *      default gate; 403 ("locked") everywhere else.
 *
 * The reason of a denial never reaches the response body; it goes to one info line per endpoint and reason. Tokens
 * are never logged.
 *
 * **A denial costs no I/O** (Arch Q2.4, 8.4): no cache, Redis or DB call, so an unauthenticated flood costs the
 * string comparisons and nothing else. The denial lines are therefore throttled in-process only, like the in-process
 * half of {@see FailureReporter}: once per {@see FailureReporter::THROTTLE_SECONDS} per endpoint and reason for as long
 * as the application's reporter lives. {@see FailureReporter::warnOnce()} is not used, because its cross-process
 * throttle calls `Cache::add()` on the default store (a SELECT and an INSERT with Laravel's default `database`
 * store). Under PHP-FPM, where nothing outlives a request, that means one info line per denied request, like the
 * web server's access log.
 *
 * @internal
 */
final class AccessPolicy
{
    /**
     * Endpoint names of `Authorize:<endpoint>`: `metrics`, and `api` for the job lists and `/live`.
     */
    public const ENDPOINTS = ['metrics', 'api'];

    /**
     * Env var named in the hints of a locked endpoint.
     */
    public const TOKEN_ENV = 'HORIZON_OBSERVABILITY_TOKEN';

    private const STATUS = ['endpoint' => 403, 'callback' => 403, 'locked' => 403, 'ip' => 403, 'token_missing' => 401, 'token_invalid' => 401];

    /**
     * Denial lines already logged: reporter (one per application) => key => monotonic seconds of the last line.
     *
     * @var WeakMap<FailureReporter, array<string, float>>|null
     */
    private static ?WeakMap $logged = null;

    /**
     * @var Closure(): float
     */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock  monotonic seconds for the log throttle, for tests; default `hrtime()`
     */
    public function __construct(
        private readonly Config $config,
        private readonly Application $app,
        private readonly FailureReporter $reporter,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * @param  string  $endpoint  `metrics`, or `api` for the job lists and `/live`; anything else is denied (403)
     */
    public function decide(Request $request, string $endpoint): AccessDecision
    {
        [$decision, $reason] = $this->evaluate($request, $endpoint);

        if ($reason !== null) {
            $this->note('access.'.$endpoint.'.'.$reason, sprintf(
                '%s request denied (%d): %s.',
                $endpoint,
                self::STATUS[$reason],
                $this->explain($reason, $endpoint),
            ), ['ip' => $request->ip(), 'path' => '/'.ltrim($request->path(), '/')]);
        }

        return $decision;
    }

    /**
     * Human-readable access mode for `about` and `check`: `callback`; the controls, e.g. `token`, `token + IPs`,
     * `IPs`; `open (local, no token)`; or `locked (set HORIZON_OBSERVABILITY_TOKEN)`. When the two endpoints differ
     * (a separate `api_token`), both are named: `metrics: token; api: api_token`. A control without a valid entry
     * reads `token (none valid: nothing passes)`.
     */
    public function mode(): string
    {
        if (HorizonObservability::authCallback() instanceof Closure) {
            return 'callback';
        }

        $metrics = $this->describe('metrics');
        $api = $this->describe('api');

        return $metrics === $api ? $metrics : sprintf('metrics: %s; api: %s', $metrics, $api);
    }

    /**
     * @return array{0: AccessDecision, 1: string|null} the decision and, for a denial, its reason
     */
    private function evaluate(Request $request, string $endpoint): array
    {
        if (! in_array($endpoint, self::ENDPOINTS, true)) {
            return [AccessDecision::Forbidden, 'endpoint'];
        }

        $callback = HorizonObservability::authCallback();

        if ($callback instanceof Closure) {
            try {
                $allowed = $callback($request) === true;
            } catch (Throwable $e) {
                $this->note('access.callback', 'access.callback failed: '.FailureReporter::describe($e), ['exception' => $e]);

                return [AccessDecision::Forbidden, null];
            }

            return $allowed ? [AccessDecision::Allowed, null] : [AccessDecision::Forbidden, 'callback'];
        }

        $tokenRequired = $this->config->requiresToken($endpoint);
        $ipRequired = $this->config->requiresIp();

        if (! $tokenRequired && ! $ipRequired) {
            return $this->local() ? [AccessDecision::Allowed, null] : [AccessDecision::Forbidden, 'locked'];
        }

        if ($ipRequired && ! $this->ipAllowed($request)) {
            return [AccessDecision::Forbidden, 'ip'];
        }

        if ($tokenRequired) {
            $token = $this->bearerToken($request);

            if ($token === null) {
                return [AccessDecision::Unauthenticated, 'token_missing'];
            }

            if (! $this->matches($token, $this->config->tokensFor($endpoint))) {
                return [AccessDecision::Unauthenticated, 'token_invalid'];
            }
        }

        return [AccessDecision::Allowed, null];
    }

    /**
     * One info line per key and throttle window for the lifetime of the reporter; touches neither the cache nor
     * anything else outside the process (see the class comment).
     *
     * @param  array<string, mixed>  $context
     */
    private function note(string $key, string $message, array $context = []): void
    {
        $now = ($this->clock)();
        $logged = self::$logged ??= new WeakMap;
        $seen = $logged[$this->reporter] ?? [];

        if (isset($seen[$key]) && $now - $seen[$key] < FailureReporter::THROTTLE_SECONDS) {
            return;
        }

        $seen[$key] = $now;
        $logged[$this->reporter] = $seen;
        $this->reporter->info($message, $context);
    }

    /**
     * The token of an `Authorization: Bearer <token>` header (scheme case-insensitive), or null.
     */
    private function bearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if (! is_string($header) || strncasecmp($header, 'Bearer ', 7) !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    /**
     * Whether the presented token equals one of the tokens; every token is compared, in constant time each.
     *
     * @param  list<string>  $tokens
     */
    private function matches(#[SensitiveParameter] string $presented, #[SensitiveParameter] array $tokens): bool
    {
        $matched = false;

        foreach ($tokens as $token) {
            $matched = hash_equals($token, $presented) || $matched;
        }

        return $matched;
    }

    private function ipAllowed(Request $request): bool
    {
        $ip = $request->ip();

        return is_string($ip) && $ip !== '' && $this->config->allowedIps !== [] && IpUtils::checkIp($ip, $this->config->allowedIps);
    }

    private function local(): bool
    {
        return $this->app->environment('local') === true;
    }

    private function apiTokenConfigured(): bool
    {
        return $this->config->apiTokens !== [] || $this->config->hasProblem('api_token');
    }

    private function tokenSetting(string $endpoint): string
    {
        return $endpoint === 'api' && $this->apiTokenConfigured() ? 'api_token' : 'token';
    }

    private function describe(string $endpoint): string
    {
        $controls = [];

        if ($this->config->requiresToken($endpoint)) {
            $setting = $this->tokenSetting($endpoint);
            $controls[] = $this->config->tokensFor($endpoint) === [] ? $setting.' (none valid: nothing passes)' : $setting;
        }

        if ($this->config->requiresIp()) {
            $controls[] = $this->config->allowedIps === [] ? 'IPs (none valid: nothing passes)' : 'IPs';
        }

        if ($controls !== []) {
            return implode(' + ', $controls);
        }

        return $this->local() ? 'open (local, no token)' : 'locked (set '.self::TOKEN_ENV.')';
    }

    private function explain(string $reason, string $endpoint): string
    {
        $setting = $this->tokenSetting($endpoint);

        return match ($reason) {
            'endpoint' => 'the route names an unknown endpoint; use Authorize:metrics or Authorize:api',
            'callback' => 'the HorizonObservability::auth() callback did not return true',
            'locked' => 'no token, IP allow-list or auth callback is configured outside the local environment; set '.self::TOKEN_ENV,
            'ip' => 'the client IP is not in allowed_ips; behind a proxy, check the TrustProxies configuration',
            'token_missing' => 'the request has no "Authorization: Bearer" token; '.$setting.' is required',
            default => 'the bearer token matches none of the valid tokens in '.$setting,
        };
    }
}
