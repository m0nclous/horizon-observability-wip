<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Ingest;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Manifest;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Recording;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\SeriesStore;
use DF\HorizonObservability\Tests\Support\Exposition;
use InvalidArgumentException;

/**
 * What Prometheus v3.15.0 stores for each target's recorded scrapes (design section 6.3), so the remote write
 * carries exactly the samples a real scrape loop would append:
 *
 * 1. the text exposition is parsed with T16's Exposition parser (`+Inf`, `-Inf` and `NaN` included);
 * 2. it must carry no `job` or `instance` label (with `honor_labels: false` Prometheus would rename them to
 *    `exported_*`) and no duplicate series (the parser rejects those);
 * 3. `job` and `instance` come from the target;
 * 4. empty label values are dropped;
 * 5. `le` of histogram buckets and `quantile` of summaries are normalised to Prometheus 3 float text
 *    (`1` -> `1.0`, `0.25`, `+Inf`, `1e+06`); remote write does not normalise, a scrape does;
 * 6. NaN and +-Inf values are kept; the sample time is the slot time;
 * 7. `up` is 1 or 0 per slot (the `scrape_*` series are not emulated: no panel reads them);
 * 8. a staleness marker is written for every series of the previous successful scrape that the next successful
 *    scrape lacks (at that scrape), for every series of the previous successful scrape at a failed slot, and at the
 *    first slot after a target's active interval ends.
 *
 * ScrapeParity (WP3) proves the emulation equals a real scrape on the PR's own expositions.
 */
final class ScrapeEmulation
{
    public static function store(Manifest $manifest, Recording $recording): SeriesStore
    {
        $store = new SeriesStore;
        $targets = [];

        foreach ($manifest->targets as $target) {
            $targets[$target['name']] = $target + ['previous' => [], 'last' => null];
        }

        foreach ($recording->scrapes() as $scrape) {
            $target = &$targets[$scrape['target']];

            if ($target === null) {
                throw new InvalidArgumentException(sprintf('The recording has a scrape of the unknown target "%s".', $scrape['target']));
            }

            $target['last'] = $scrape['t'];
            $base = ['job' => $target['job'], 'instance' => $target['instance']];
            $current = [];

            if ($scrape['ok'] && is_string($scrape['exposition'])) {
                foreach (self::samples(Exposition::parse($scrape['exposition'])) as [$name, $labels, $value]) {
                    $series = ['__name__' => $name, ...$labels, ...$base];
                    $store->append($series, $scrape['t'], $value);
                    $current[SeriesStore::key($series)] = $series;
                }
            }

            foreach ($target['previous'] as $key => $series) {
                if (! isset($current[$key])) {
                    $store->append($series, $scrape['t'], null);
                }
            }

            $store->append(['__name__' => 'up', ...$base], $scrape['t'], $scrape['ok'] ? 1.0 : 0.0);
            $target['previous'] = $current;
            unset($target);
        }

        // The end of a target's active interval: its series go stale at the next slot
        foreach ($targets as $target) {
            if ($target['last'] === null || $manifest->end * 1000 <= $target['to'] * 1000) {
                continue;
            }

            $next = $target['last'] + $target['interval'] * 1000;

            foreach ($target['previous'] as $series) {
                $store->append($series, $next, null);
            }
        }

        return $store;
    }

    /**
     * The samples of an exposition as Prometheus appends them: name, labels (empty values dropped, `le`/`quantile`
     * normalised) and value.
     *
     * @return list<array{0: string, 1: array<string, string>, 2: float}>
     */
    public static function samples(Exposition $exposition): array
    {
        $samples = [];

        foreach ($exposition->samples as $name => $series) {
            $type = self::type($exposition, $name);

            foreach ($series as $key => $value) {
                $labels = [];

                foreach (self::labels($key) as $label => $text) {
                    if ($label === 'job' || $label === 'instance') {
                        throw new InvalidArgumentException(sprintf('%s{%s} carries a "%s" label, which a scrape with honor_labels: false would rename.', $name, $key, $label));
                    }

                    if ($text === '') {
                        continue;
                    }

                    if (($label === 'le' && $type === 'histogram') || ($label === 'quantile' && $type === 'summary')) {
                        $text = self::float($text);
                    }

                    $labels[$label] = $text;
                }

                $samples[] = [(string) $name, $labels, $value];
            }
        }

        return $samples;
    }

    /**
     * Prometheus 3's float text of a `le` or `quantile` value (textparse `formatOpenMetricsFloat`): Go's shortest
     * `%g`, with `.0` appended when that has neither a `.` nor an exponent.
     */
    public static function float(string $text): string
    {
        $value = match ($text) {
            '+Inf', 'Inf', '+inf', 'inf' => INF,
            '-Inf', '-inf' => -INF,
            'NaN', 'nan' => NAN,
            default => is_numeric($text) ? (float) $text : throw new InvalidArgumentException(sprintf('"%s" is not a float.', $text)),
        };

        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        $formatted = self::shortestG($value);

        return str_contains($formatted, '.') || str_contains($formatted, 'e') ? $formatted : $formatted.'.0';
    }

    /**
     * Go's `strconv.FormatFloat(f, 'g', -1, 64)`: the shortest digits that round-trip, in `%e` form (at least two
     * exponent digits) when the decimal exponent is below -4 or at least 6, else in `%f` form.
     */
    private static function shortestG(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }

        $negative = $value < 0;
        // The shortest round-trip digits, correctly rounded at that precision (Go picks the closest shortest form)
        $repr = sprintf('%.16e', abs($value));

        for ($precision = 1; $precision <= 17; $precision++) {
            $candidate = sprintf('%.'.($precision - 1).'e', abs($value));

            if ((float) $candidate === abs($value)) {
                $repr = $candidate;

                break;
            }
        }

        [$mantissa, $exp] = explode('e', $repr);
        $digits = str_replace('.', '', $mantissa);
        $digits = rtrim($digits, '0') === '' ? '0' : rtrim($digits, '0');
        $exponent = (int) $exp;
        $count = strlen($digits);

        // Go: eprec = prec (-1 -> shortest); if eprec > digs.nd && digs.nd >= digs.dp { eprec = digs.nd };
        // if shortest { eprec = 6 }; exp := digs.dp - 1; if exp < -4 || exp >= eprec { %e }
        if ($exponent < -4 || $exponent >= 6) {
            $text = $digits[0].($count > 1 ? '.'.substr($digits, 1) : '').'e'.($exponent < 0 ? '-' : '+').str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
        } elseif ($exponent >= 0) {
            $whole = substr(str_pad($digits, $exponent + 1, '0'), 0, $exponent + 1);
            $fraction = substr($digits, $exponent + 1);
            $text = $whole.($fraction === '' ? '' : '.'.$fraction);
        } else {
            $text = '0.'.str_repeat('0', -$exponent - 1).$digits;
        }

        return ($negative ? '-' : '').$text;
    }

    private static function type(Exposition $exposition, string $name): ?string
    {
        if (isset($exposition->types[$name])) {
            return $exposition->types[$name];
        }

        foreach (['_bucket', '_sum', '_count', '_created'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $family = substr($name, 0, -strlen($suffix));

                if (isset($exposition->types[$family])) {
                    return $exposition->types[$family];
                }
            }
        }

        return null;
    }

    /**
     * The labels of an Exposition labels key (`a="1",b="x\"y"`), unescaped.
     *
     * @return array<string, string>
     */
    private static function labels(string $key): array
    {
        if ($key === '') {
            return [];
        }

        $labels = [];
        $offset = 0;
        $length = strlen($key);

        while ($offset < $length) {
            if (preg_match('/\G([a-zA-Z_][a-zA-Z0-9_]*)="/', $key, $match, 0, $offset) !== 1) {
                throw new InvalidArgumentException(sprintf('Bad labels key at %d: %s', $offset, $key));
            }

            $offset += strlen($match[0]);
            $value = '';

            while ($key[$offset] !== '"') {
                if ($key[$offset] === '\\') {
                    $value .= match ($key[$offset + 1]) {
                        'n' => "\n",
                        default => $key[$offset + 1],
                    };
                    $offset += 2;

                    continue;
                }

                $value .= $key[$offset++];
            }

            $labels[$match[1]] = $value;
            $offset += 2;
        }

        return $labels;
    }
}
