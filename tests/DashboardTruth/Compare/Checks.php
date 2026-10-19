<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Compare;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\CaseDef;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Category;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Observation as Captured;

/**
 * The checks of an observation that no spec owns (design sections 5.2 and 11.2), each a failure of its own:
 *
 * - infrastructure: the completion criterion did not hold, a request failed (and was not re-sent and answered) or
 *   answered without a result, a request
 *   is attributed to no panel (neither a panel id of the render nor a clone's request id in the scene), a query was
 *   sent after the clock release for another range, the router answered 5xx;
 * - case setup: a `var-*` URL value that is not an option of its variable (unless the case is `custom`), or a `$`
 *   left in a captured expression other than the backend macros and `label_replace` group references;
 * - dashboard defect: a query error frame, a panel error indicator or scene error, a router 4xx.
 */
final class Checks
{
    /**
     * The `$` references Grafana's backend substitutes (design section 5.2).
     */
    public const MACROS = ['$__interval_ms', '$__interval', '$__range_s', '$__range_ms', '$__range', '$__rate_interval'];

    /**
     * `$__all` is the value Grafana sends for All in the `queryparam` format of a variable without an all value
     * (`var-queue=$__all`), which the job-list endpoints read as All: allowed in an Infinity URL only, never in a
     * PromQL expression, where it would select nothing.
     */
    public const URL_ALL = '$__all';

    /**
     * @param  array<string, mixed>  $planned  the plan's entry of the case
     * @return list<Assertion>
     */
    public static function run(CaseDef $case, array $planned, Captured $observation): array
    {
        $grafana = $observation->grafana();
        $failures = [];
        $fail = static function (Category $category, string $source, string $expected, string $actual, ?int $panel = null, ?string $refId = null) use ($case, $grafana, &$failures): void {
            $failures[] = new Assertion($case->id, $grafana, $panel, $refId, 'check', $source, false, $category, $expected, $actual);
        };

        if (! $observation->complete()) {
            $fail(Category::Infrastructure, 'completion', 'every target answered, every panel Done, 700 ms quiet within 90 s', implode('; ', $observation->incomplete()));
        }

        // Case setup: every URL value is an option of its variable
        $variables = $observation->variables();

        foreach ($case->vars as $name => $values) {
            $variable = $variables[$name] ?? null;

            if ($variable === null) {
                $fail(Category::CaseSetup, 'variable', sprintf('a variable "%s"', $name), 'the scene has no such variable');

                continue;
            }

            $options = array_map(static fn (array $option): string => (string) (is_scalar($option['value']) ? $option['value'] : json_encode($option['value'])), $variable['options']);

            if (in_array($variable['type'], ['textbox', 'constant'], true) || $case->custom) {
                continue;
            }

            foreach ($values as $value) {
                if ($value !== '$__all' && ! in_array($value, $options, true)) {
                    $fail(Category::CaseSetup, 'variable', sprintf('var-%s=%s is an option of %s', $name, $value, $name), sprintf('options: %s', implode(', ', array_slice($options, 0, 20)) ?: 'none'));
                }
            }
        }

        // Attribution, errors and leftover variables of every request
        $known = array_map(static fn (array $panel): string => (string) $panel['id'], $planned['panels']);

        foreach ($observation->panels() as $panel) {
            if ($panel['requestPanelId'] !== null) {
                $known[] = (string) $panel['requestPanelId'];
            }
        }

        $range = null;

        foreach ($observation->requests() as $index => $request) {
            $panelId = $request['panelId'];
            $panel = is_string($panelId) && ctype_digit($panelId) && (int) $panelId < 1000000 ? (int) $panelId : null;

            if (! is_string($panelId) || ! in_array($panelId, $known, true)) {
                $fail(Category::Infrastructure, 'attribution', 'a request of a panel of the render or of a scene clone', sprintf('X-Panel-Id %s (%s)', var_export($panelId, true), $request['panelTitle'] ?? '?'));
            }

            if (is_string($request['error'] ?? null)) {
                // The frontend aborts a request it re-sends (a variable settled meanwhile): only a failure when no
                // later request of the panel answered
                if (! self::answeredLater($observation, $index, (string) $panelId)) {
                    $fail(Category::Infrastructure, 'request', 'an answer', (string) $request['error'], $panel);
                }

                continue;
            }

            $body = is_array($request['body']) ? $request['body'] : [];
            $released = $observation->data['released'] ?? null;

            if ($range === null) {
                $range = [$body['from'] ?? null, $body['to'] ?? null];
            } elseif (is_array($released) && $index >= (int) $released['requests'] && [$body['from'] ?? null, $body['to'] ?? null] !== $range) {
                $fail(Category::Infrastructure, 'clock', sprintf('every query on the range %s..%s', $range[0], $range[1]), sprintf('a query after the clock release on %s..%s', $body['from'] ?? '?', $body['to'] ?? '?'), $panel);
            }

            foreach (is_array($body['queries'] ?? null) ? $body['queries'] : [] as $query) {
                if (! is_array($query)) {
                    continue;
                }

                $refId = (string) ($query['refId'] ?? '');
                $leftover = self::leftover($query);

                if ($leftover !== null) {
                    $fail(Category::CaseSetup, 'interpolation', 'no dashboard variable left in the sent query', $leftover, $panel, $refId);
                }

                $response = is_array($request['response']) ? $request['response'] : null;
                $error = Observation::error($response, $refId);

                if ($error !== null && $response !== null && isset($response['results'][$refId])) {
                    $fail(Category::DashboardDefect, 'query', 'a result without an error', $error, $panel, $refId);
                } elseif ($error !== null) {
                    $fail(Category::Infrastructure, 'query', 'a result', sprintf('HTTP %s: %s', var_export($request['status'], true), $error), $panel, $refId);
                }
            }
        }

        foreach ($observation->panels() as $key => $panel) {
            if ($panel['errors'] !== []) {
                $fail(Category::DashboardDefect, 'panel', $key.' without errors', implode('; ', $panel['errors']));
            }

            $dom = $observation->dom($key);

            if ($dom !== null && $dom['error']) {
                $fail(Category::DashboardDefect, 'panel', $key.' without an error indicator', 'the panel shows an error');
            }
        }

        foreach ($observation->router() as $entry) {
            $status = (int) $entry['status'];

            if ($status >= 400) {
                $fail($status >= 500 ? Category::Infrastructure : Category::DashboardDefect, 'router', 'a 2xx answer of the application', sprintf('%d for %s %s', $status, $entry['path'], json_encode($entry['query'])));
            }
        }

        return $failures;
    }

    private static function answeredLater(Captured $observation, int $index, string $panelId): bool
    {
        foreach (array_slice($observation->requests(), $index + 1) as $later) {
            if ($later['panelId'] === $panelId && is_array($later['response']) && ! is_string($later['error'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A `$` reference left in a sent query other than the backend macros, `label_replace` group references (`$1`)
     * and `${1}`, and `$__all` in a URL, or null.
     *
     * @param  array<array-key, mixed>  $query
     */
    public static function leftover(array $query): ?string
    {
        $texts = [];

        foreach (['expr', 'url', 'interval'] as $field) {
            if (is_string($query[$field] ?? null)) {
                $texts[$field] = $query[$field];
            }
        }

        foreach ($texts as $field => $text) {
            $text = str_replace($field === 'url' ? [...self::MACROS, self::URL_ALL] : self::MACROS, '', $text);
            $text = (string) preg_replace('/\$\{?\d\}?/', '', $text);

            if (preg_match('/\$\{?[A-Za-z_][A-Za-z0-9_]*/', $text, $match) === 1) {
                return sprintf('%s holds %s', $field, $match[0]);
            }
        }

        return null;
    }
}
