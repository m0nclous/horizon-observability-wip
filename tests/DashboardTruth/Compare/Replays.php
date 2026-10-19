<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Compare;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Durations;
use RuntimeException;

/**
 * The only requests T17 itself sends (design section 5.6): a captured `/api/ds/query` body re-posted to the same
 * Grafana with only `maxDataPoints` and `intervalMs` changed, at the scrape interval S (`intervalMs` = S, enough data
 * points for S) and at the next coarser step of Grafana's ladder after the captured one. The frontend-interpolated
 * variables stay as captured, and the backend substitutes the macros again for the new step; an unchanged replay
 * returns byte-identical frames (design X7). Expectations are computed on the replay's own grid.
 */
final readonly class Replays
{
    /**
     * Grafana's `safeResolution`: at most 11,000 points per series.
     */
    private const MAX_POINTS = 11000;

    public function __construct(private string $grafanaUrl, private string $auth = 'admin:admin') {}

    /**
     * The replay steps for a target captured at `$capturedMs` on a dataset scraped every `$scrapeMs`: S and the next
     * coarser Grafana step (both distinct from each other; S may equal the captured step).
     *
     * @return list<int>
     */
    public static function steps(int $scrapeMs, int $capturedMs): array
    {
        return array_values(array_unique([$scrapeMs, Durations::coarser($capturedMs)]));
    }

    /**
     * The body of a captured request at another step.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    public static function body(array $body, int $stepMs): array
    {
        $range = (int) ($body['to'] ?? 0) - (int) ($body['from'] ?? 0);

        foreach ($body['queries'] as &$query) {
            $query['intervalMs'] = $stepMs;
            $query['maxDataPoints'] = min(self::MAX_POINTS, intdiv($range, $stepMs) + 1);
        }

        unset($query);

        return $body;
    }

    /**
     * Re-post a captured body at a step; the decoded response.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    public function post(string $url, array $body, int $stepMs): array
    {
        $curl = curl_init(rtrim($this->grafanaUrl, '/').$url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(self::body($body, $stepMs), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->auth,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Dashboard-Truth: replay'],
            CURLOPT_TIMEOUT => 60,
        ]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (! is_string($response)) {
            throw new RuntimeException(sprintf('The replay to %s failed: %s', $this->grafanaUrl, curl_error($curl)));
        }

        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('The replay to %s answered %d: %s', $this->grafanaUrl, $status, substr($response, 0, 300)));
        }

        return $decoded;
    }
}
