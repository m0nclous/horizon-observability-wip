<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live\Stream;

use Closure;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Http\Client\Factory;
use SensitiveParameter;
use Throwable;

/**
 * `POST {grafana}/api/live/push/{id}` of a line-protocol body with a service-account token (Addendum R4.6), through
 * Laravel's HTTP client (`Http::fake()` works): 2 s timeout, 1 s connect timeout, no retries, no redirects.
 *
 * A push never throws: connection errors and timeouts are a {@see PushResult} without a status, a non-2xx answer one
 * with it. The error text is short and never contains the token. {@see self::hint()} gives the operator hint for a
 * status (Addendum R4.10), shared with `check --live-push-test`.
 *
 * @internal
 */
final readonly class GrafanaLivePusher
{
    public const TIMEOUT_SECONDS = 2;

    public const CONNECT_TIMEOUT_SECONDS = 1;

    /**
     * Bytes of a Grafana error body kept in the error text.
     */
    public const MAX_ERROR_BYTES = 200;

    /**
     * @var Closure(): float
     */
    private Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock  monotonic seconds, for tests; default `hrtime()`
     */
    public function __construct(private Factory $http, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * The push URL of a Grafana base URL and stream id.
     */
    public static function url(string $baseUrl, string $streamId): string
    {
        return rtrim($baseUrl, '/').'/api/live/push/'.rawurlencode($streamId);
    }

    public function push(string $baseUrl, string $streamId, #[SensitiveParameter] string $token, string $body): PushResult
    {
        $started = ($this->clock)();

        try {
            $response = $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                // A redirect would resend the token elsewhere or turn the POST into a GET that "succeeds".
                ->withoutRedirecting()
                ->withToken($token)
                ->withBody($body, 'text/plain')
                ->post(self::url($baseUrl, $streamId));
        } catch (Throwable $e) {
            return new PushResult(false, null, $this->redact(FailureReporter::describe($e), $token), ($this->clock)() - $started);
        }

        $seconds = ($this->clock)() - $started;
        $status = $response->status();

        if ($response->successful()) {
            return new PushResult(true, $status, null, $seconds);
        }

        $text = trim((string) strtok(trim($response->body()), "\r\n"));
        $text = strlen($text) > self::MAX_ERROR_BYTES ? mb_strcut($text, 0, self::MAX_ERROR_BYTES - 3, 'UTF-8').'...' : $text;

        return new PushResult(false, $status, $this->redact('HTTP '.$status.($text === '' ? '' : ': '.$text), $token), $seconds);
    }

    /**
     * A Grafana URL for logs and output: without the user and password of the URL, if any.
     */
    public static function display(string $url): string
    {
        return (string) preg_replace('~^([a-z][a-z0-9+.-]*://)[^/@]*@~i', '$1', $url);
    }

    /**
     * What to check for a failed push, by HTTP status (null: no answer).
     */
    public static function hint(?int $status): string
    {
        return match (true) {
            $status === null => 'Grafana is unreachable from the app or did not answer within 2 s; check HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL',
            $status === 401 => 'service account token invalid or missing; check HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN',
            $status === 403 => 'service account token invalid, or role Editor needed on Grafana 13 (live:push)',
            $status === 404 => 'no Grafana Live push endpoint at this URL; check HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL and that Grafana Live is enabled',
            $status >= 300 && $status < 400 => 'Grafana redirected the push (pushes do not follow redirects); set HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL to the final URL, e.g. https',
            $status >= 500 => 'Grafana rejected or failed to process the push',
            default => 'Grafana refused the push',
        };
    }

    /**
     * The token and any `user:password@` of a URL out of an error text.
     */
    private function redact(string $text, string $token): string
    {
        $text = (string) preg_replace('~(://)[^/@\s]*@~', '$1', $text);

        return $token === '' ? $text : str_replace($token, '[redacted]', $text);
    }
}
