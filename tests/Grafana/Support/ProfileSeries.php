<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Grafana\Support;

use DF\HorizonObservability\Grafana\Promql;
use Workbench\App\Perf\SyntheticModel;

/**
 * The medium perf profile (Workbench\App\Perf\SyntheticModel) for one application as promtool input series, and
 * the helpers of the truth tests that evaluate rendered expressions on it: Grafana's interpolation at 19m with a
 * 15-minute range, and the comparison of two expressions.
 */
final class ProfileSeries
{
    /**
     * The batch kind the tests select (Batch = one kind).
     */
    public const KIND = 'import_catalog';

    /**
     * The medium perf profile for one application, 20 points at 1 minute, as promtool input series; and the classes,
     * queues and per-kind batch classes it holds.
     *
     * @return array{series: list<array{series: string, values: string}>, classes: list<string>, queues: list<string>, kinds: array<string, array{classes: list<string>, queues: list<string>}>}
     */
    public static function data(): array
    {
        static $data = null;

        if ($data !== null) {
            return $data;
        }

        $model = new SyntheticModel('medium');
        $values = [];
        $classes = [];
        $queues = [];
        $kinds = [];

        for ($i = 0; $i < 20; $i++) {
            $t = 1_790_000_000 + 60 * $i;

            foreach ($model->series($t) as [$name, $labels, $value]) {
                $selector = 'ho_'.$name.'{job="app",instance="app:8080"'.($labels === '' ? '' : ','.$labels).'}';
                $values[$selector][] = $value;

                if ($i === 19 && in_array($name, ['queue_jobs_total', 'queue_jobs_pending', 'queue_jobs_reserved'], true)
                    && preg_match('/job_class="((?:[^"\\\\]|\\\\.)*)"/', $labels, $class) === 1) {
                    $classes[stripcslashes($class[1])] = true;
                }

                if ($i === 19 && $name === 'queue_pending_jobs' && preg_match('/queue="([^"]+)"/', $labels, $queue) === 1) {
                    $queues[$queue[1]] = true;
                }

                if ($i === 19 && $name === 'queue_batch_job_classes'
                    && preg_match('/batch="([^"]+)",queue="([^"]+)",job_class="((?:[^"\\\\]|\\\\.)*)"/', $labels, $link) === 1) {
                    $kinds[$link[1]]['classes'][stripcslashes($link[3])] = true;
                    $kinds[$link[1]]['queues'][$link[2]] = true;
                }
            }
        }

        $series = [];

        foreach ($values as $selector => $list) {
            $series[] = ['series' => $selector, 'values' => implode(' ', array_map(static fn (int|float $value): string => (string) $value, $list))];
        }

        return $data = [
            'series' => $series,
            'classes' => array_keys($classes),
            'queues' => array_keys($queues),
            'kinds' => array_map(static fn (array $kind): array => array_map(array_keys(...), $kind), $kinds),
        ];
    }

    /**
     * Grafana's Prometheus regex of a multi-value selection, as it appears inside a PromQL string literal.
     *
     * @param  list<string>  $values
     */
    public static function regex(array $values): string
    {
        $regex = '('.implode('|', array_map(Promql::literalRegex(...), $values)).')';

        return substr(Promql::quote($regex), 1, -1);
    }

    /**
     * An expression as Grafana sends it at 19m with a 15-minute range and every filter at "All" except Batch ($kind,
     * or "All" for null), Job "All" being $jobClass.
     */
    public static function interpolate(string $expr, ?string $kind, string $jobClass): string
    {
        $expr = strtr($expr, [
            '${__from:date:seconds}' => '240',
            '${__to:date:seconds}' => '1140',
            '${__from}' => '240000',
            '${__to}' => '1140000',
            '$__interval_ms' => '60000',
            '${__interval_ms}' => '60000',
            '$__interval' => '1m',
            '$__range' => '900s',
            '$busy_window' => '2m',
            '$scrape_interval' => '1m',
            '$prometheus_job' => '.+',
            '${batch_kind:text}' => $kind ?? 'All',
            '$batch_kind' => $kind ?? '.*',
            '$job_class' => $jobClass,
            '${horizon_url}' => 'https://app.test/horizon',
        ]);
        $expr = (string) preg_replace('/\$\{[A-Za-z_]\w*:text\}/', 'All', $expr);

        // $1 in label_replace() replacements is not a variable
        return (string) preg_replace('/\$\{[A-Za-z_]\w*\}|\$[A-Za-z_]\w*/', '.*', $expr);
    }

    /**
     * Non-empty where two instant vectors differ: a series in one only, or another value (NaN equals NaN).
     */
    public static function difference(string $a, string $b): string
    {
        return '(('.$a.') != ('.$b.') unless (('.$a.') != ('.$a.'))) or (('.$a.') unless ('.$b.')) or (('.$b.') unless ('.$a.'))';
    }
}
