<?php

declare(strict_types=1);

namespace Workbench\App\Perf;

use DF\HorizonObservability\Http\RoutePath;
use Generator;
use InvalidArgumentException;

/**
 * Deterministic synthetic metrics for the dashboard performance profile (docker/perf/README.md). A PHP port of the
 * Python probe model (`synth.py`) whose history the pilot dashboard was first measured on: every value is a pure
 * function of the Unix time, so the backfill (workbench:perf-data) and the continuation exporter
 * (workbench:perf-exporter) produce one continuous history.
 *
 * Profiles:
 * - medium: 4 supervisors, 12 queues, 160 job classes (180 queue/class tuples), 16 duration buckets and 8 batch kinds
 *   with a new batch every 10 minutes; about 5,300 series. Identical to the probe model, value for value.
 * - large: 10 supervisors, 40 queues, 500 job classes, same rules.
 *
 * Schemas:
 * - legacy: the pilot's family names (what the pilot dashboard read; docker/perf/README.md restores it), namespace "office";
 * - catalog: the package's `Metric` catalog (`queue_oldest_pending_job_age_seconds`, `horizon_info{api_path}`, the
 *   `exporter_*` self-metrics, no `horizon_current_processes`), namespace "ho".
 *
 * The model never emits `job` or `instance`: Prometheus adds them on scrape, and an exported `job` label would be
 * renamed `exported_job` and duplicate every series. The backfill passes them as extra labels instead.
 */
final class SyntheticModel
{
    public const LEGACY = 'legacy';

    public const CATALOG = 'catalog';

    public const PROFILES = ['medium', 'large'];

    private const CONNECTION = 'redis';

    private const DAY = 86400.0;

    private const BOUNDS = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600, 1800, 3600];

    private const STATUS_SHARE = ['completed' => 0.9825, 'released' => 0.002, 'retried' => 0.01, 'failed' => 0.005, 'timed_out' => 0.0005];

    private const DOMAINS = ['Orders', 'Payments', 'Crm', 'Mail', 'Reports', 'Exports', 'Imports', 'Catalog', 'Delivery', 'Loyalty'];

    private const VERBS = ['Sync', 'Send', 'Build', 'Process', 'Import', 'Export', 'Recalculate', 'Notify', 'Refresh', 'Archive',
        'Validate', 'Dispatch', 'Index', 'Cleanup', 'Charge', 'Render'];

    private const BATCH_KINDS = ['import_catalog', 'export_orders', 'recalc_loyalty', 'send_campaign', 'sync_crm',
        'reindex_search', 'build_reports', 'cleanup_media'];

    private const SLOW_QUEUES = ['slow', 'reports', 'exports', 'imports'];

    private const HORIZON_URL = 'https://office.example.test/horizon';

    private const TTS_URL = 'http://piper:5000';

    /** @var array<string, array{queues: list<string>, min: int, max: int, balance: string}> */
    private array $supervisors;

    /** @var list<string> */
    private array $queues;

    /** @var array<string, int> */
    private array $waits;

    /** @var list<string> */
    private array $classes;

    /** @var list<array{queue: string, class: string, rate: float, mean: float, base: string, shares: list<float>}> */
    private array $tuples = [];

    /** @var array<string, list<float>> queue => wait bucket shares */
    private array $waitShares = [];

    /** @var array<string, list<string>> batch kind => job classes of its queue (at most 3) */
    private array $batchClasses = [];

    /** @var array<string, int> */
    private array $hashes = [];

    /** @var list<string> */
    private array $les = [];

    public function __construct(
        public readonly string $profile = 'medium',
        public readonly string $schema = self::CATALOG,
        private readonly ?string $namespace = null,
    ) {
        if (! in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown profile "%s"; use %s.', $profile, implode(' or ', self::PROFILES)));
        }

        if (! in_array($schema, [self::LEGACY, self::CATALOG], true)) {
            throw new InvalidArgumentException(sprintf('Unknown schema "%s"; use legacy or catalog.', $schema));
        }

        $this->build();
    }

    /**
     * The metric name prefix: "<namespace>_", or '' for an empty namespace.
     */
    public function prefix(): string
    {
        $namespace = $this->namespace ?? ($this->schema === self::LEGACY ? 'office' : 'ho');

        return $namespace === '' ? '' : $namespace.'_';
    }

    /**
     * Every series at time `$t`: [family without prefix, label body (`k="v",...`, escaped), value].
     *
     * @return Generator<int, array{0: string, 1: string, 2: int|float}>
     */
    public function series(int|float $t): Generator
    {
        $legacy = $this->schema === self::LEGACY;
        $c = self::CONNECTION;

        yield ['horizon_status', '', $this->mod($t, 6 * 3600) > 120 ? 1 : 0];

        foreach ($this->supervisors as $supervisor => $settings) {
            $s = $this->labels(['supervisor' => $supervisor]);
            yield ['horizon_supervisor_min_processes', $s, $settings['min']];
            yield ['horizon_supervisor_max_processes', $s, $settings['max']];
            yield ['horizon_supervisor_info', $this->labels(['supervisor' => $supervisor, 'balance' => $settings['balance'], 'connection' => $c]), 1];

            foreach ($settings['queues'] as $queue) {
                $mx = $settings['max'];
                $busy = 1 + (int) (($mx - 1) * (0.5 + 0.5 * sin($t / 900 + $this->u('p', $queue) * 6)) * (strlen($queue) % 3 + 1) / 3);
                $processes = max($settings['min'], min($mx, $busy));
                yield ['horizon_supervisor_processes', $this->labels(['supervisor' => $supervisor, 'queue' => $queue]), $processes];

                if (! $legacy) {
                    // A balanced pool per queue: the pool label is the queue
                    yield ['horizon_supervisor_queues', $this->labels(['supervisor' => $supervisor, 'queue' => $queue, 'pool' => $queue]), 1];
                }

                if ($legacy) {
                    yield ['horizon_current_processes', $this->labels(['queue' => $queue]), $processes];
                }
            }
        }

        yield ['horizon_info', $legacy
            ? $this->labels(['url' => self::HORIZON_URL, 'tts_url' => self::TTS_URL])
            : $this->labels(['url' => self::HORIZON_URL, 'api_path' => '/horizon/'.RoutePath::SEGMENT, 'tts_url' => self::TTS_URL]), 1];

        $waitTotal = $this->diurnalIntegral(0.4, $t);

        foreach ($this->queues as $queue) {
            $cq = $this->labels(['connection' => $c, 'queue' => $queue]);
            $spike = $this->spike($t, 1800, 300, $queue);
            yield ['horizon_wait_threshold_seconds', $cq, $this->waits[$queue]];
            $pending = (int) (40 * $spike + 3 * (0.5 + 0.5 * sin($t / 300 + $this->h($queue) % 7)));
            yield ['queue_pending_jobs', $cq, $pending];
            $delayed = (int) (5 + 20 * $this->spike($t, 3600, 900, 'd'.$queue));
            yield ['queue_delayed_jobs', $cq, $delayed];
            yield ['queue_delayed_due_jobs', $cq, (int) ($delayed * 0.1)];
            yield [$legacy ? 'queue_oldest_pending_job_age' : 'queue_oldest_pending_job_age_seconds', $cq,
                $pending !== 0 ? (int) ($this->waits[$queue] * 1.5 * $spike) : 0];

            foreach ($this->waitShares[$queue] as $bucket => $share) {
                yield ['queue_job_wait_seconds_bucket', $cq.',le="'.$this->les[$bucket].'"', (int) ($waitTotal * $share)];
            }

            yield ['queue_job_wait_seconds_sum', $cq, self::round3($this->diurnalIntegral(0.4, $t) * 3.1)];
            yield ['queue_job_wait_seconds_count', $cq, (int) $this->diurnalIntegral(0.4, $t)];
        }

        foreach ($this->classes as $class) {
            yield ['queue_job_classes', $this->labels(['job_class' => $class]), 1];
        }

        foreach ($this->tuples as $tuple) {
            $base = $tuple['base'];
            $total = $this->diurnalIntegral($tuple['rate'], $t);

            foreach (self::STATUS_SHARE as $status => $share) {
                yield ['queue_jobs_total', $base.',status="'.$status.'"', (int) ($total * $share)];
            }

            $count = (int) ($total * (1 - self::STATUS_SHARE['released']));

            foreach ($tuple['shares'] as $bucket => $share) {
                yield ['queue_job_duration_seconds_bucket', $base.',le="'.$this->les[$bucket].'"', (int) ($count * $share)];
            }

            yield ['queue_job_duration_seconds_sum', $base, self::round3($count * $tuple['mean'])];
            yield ['queue_job_duration_seconds_count', $base, $count];
            $rate = $this->rateAt($tuple['rate'], $t);

            if ($rate > 0.02) {
                yield ['queue_job_duration_min_seconds', $base, self::round3($tuple['mean'] * 0.3)];
                yield ['queue_job_duration_max_seconds', $base, self::round3($tuple['mean'] * 3.2)];
            }

            $busy = $rate * $tuple['mean'];

            if ($busy > 0.3) {
                yield ['queue_jobs_reserved', $base, max(1, (int) $busy)];
            }

            $pending = (int) ($busy * 3 * $this->spike($t, 1800, 300, $tuple['queue']));

            if ($pending > 0) {
                yield ['queue_jobs_pending', $base, $pending];
            }
        }

        yield from $this->batches($t);

        if (! $legacy) {
            foreach (['horizon', 'queues', 'attempts', 'job_classes', 'batches'] as $index => $collector) {
                yield ['exporter_collector_success', $this->labels(['collector' => $collector]), 1];
                yield ['exporter_collector_duration_seconds', $this->labels(['collector' => $collector]), 0.002 + $index / 1000];
            }

            yield ['exporter_build_info', $this->labels(['version' => '0.1.0', 'php' => '8.4.0', 'laravel' => '13.0.0', 'horizon' => '5.50.1', 'redis_client' => 'phpredis 6.2.0']), 1];
            yield ['exporter_config_problems', '', 0];
            yield ['exporter_attempts_dropped_total', '', 0];
        }
    }

    /**
     * The exposition the continuation exporter serves: one line per series, no TYPE or HELP lines (as the probe).
     */
    public function exposition(int|float $t): string
    {
        $prefix = $this->prefix();
        $out = '';

        foreach ($this->series($t) as [$name, $labels, $value]) {
            $out .= $prefix.$name.'{'.$labels.'} '.self::format($value)."\n";
        }

        return $out;
    }

    /**
     * OpenMetrics sample lines at time `$t` (seconds) with the labels Prometheus would add on scrape (`job`,
     * `instance`), for `promtool tsdb create-blocks-from openmetrics`.
     *
     * @param  string  $extra  escaped label body appended to every series, e.g. `job="perf",instance="perf"`
     */
    public function samples(int $t, string $extra): string
    {
        $prefix = $this->prefix();
        $suffix = ' '.$t."\n";
        $out = '';

        foreach ($this->series($t) as [$name, $labels, $value]) {
            if ($name === 'horizon_info') {
                continue; // written separately with 7 days of history (see horizonInfo())
            }

            $out .= $prefix.$name.'{'.($labels === '' ? $extra : $labels.','.$extra).'} '.self::format($value).$suffix;
        }

        return $out;
    }

    /**
     * The `horizon_info` sample line at `$t`: the dashboard reads it with a 7-day lookback.
     */
    public function horizonInfo(int $t, string $extra): string
    {
        foreach ($this->series($t) as [$name, $labels, $value]) {
            if ($name === 'horizon_info') {
                return $this->prefix().$name.'{'.$labels.','.$extra.'} '.self::format($value).' '.$t."\n";
            }
        }

        return '';
    }

    /**
     * A value as Python's str() prints it: integers plain, floats in their shortest round-trip form with ".0" when
     * integral.
     */
    public static function format(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_finite($value) && floor($value) === $value && abs($value) < 1e16) {
            return sprintf('%.1f', $value);
        }

        return var_export($value, true);
    }

    /**
     * Python's round(x, 3): the correctly rounded decimal (dtoa), not PHP's pre-rounding round().
     */
    public static function round3(float $value): float
    {
        return (float) sprintf('%.3f', $value);
    }

    /**
     * The error function as glibc 2.39 computes it (fdlibm `s_erf.c`, the same operations in the same order), which
     * Python's math.erf() calls; PHP has no erf().
     */
    public static function erf(float $x): float
    {
        $hx = self::highWord($x);
        $ix = $hx & 0x7FFFFFFF;

        if ($ix >= 0x7FF00000) {
            return is_nan($x) ? $x : ($x > 0 ? 1.0 : -1.0);
        }

        if ($ix < 0x3FEB0000) { // |x| < 0.84375
            if ($ix < 0x3E300000) { // |x| < 2**-28
                return $x + 1.28379167095512586316e-01 * $x;
            }

            $z = $x * $x;
            $r1 = 1.28379167095512558561e-01 + $z * -3.25042107247001499370e-01;
            $z2 = $z * $z;
            $r2 = -2.84817495755985104766e-02 + $z * -5.77027029648944159157e-03;
            $z4 = $z2 * $z2;
            $s1 = 1.0 + $z * 3.97917223959155352819e-01;
            $s2 = 6.50222499887672944485e-02 + $z * 5.08130628187576562776e-03;
            $s3 = 1.32494738004321644526e-04 + $z * -3.96022827877536812320e-06;
            $r = $r1 + $z2 * $r2 + $z4 * -2.37630166566501626084e-05;
            $s = $s1 + $z2 * $s2 + $z4 * $s3;
            $y = $r / $s;

            return $x + $x * $y;
        }

        if ($ix < 0x3FF40000) { // 0.84375 <= |x| < 1.25
            $s = abs($x) - 1.0;
            $p1 = -2.36211856075265944077e-03 + $s * 4.14856118683748331666e-01;
            $s2 = $s * $s;
            $q1 = 1.0 + $s * 1.06420880400844228286e-01;
            $s4 = $s2 * $s2;
            $p2 = -3.72207876035701323847e-01 + $s * 3.18346619901161753674e-01;
            $s6 = $s4 * $s2;
            $q2 = 5.40397917702171048937e-01 + $s * 7.18286544141962662868e-02;
            $p3 = -1.10894694282396677476e-01 + $s * 3.54783043256182359371e-02;
            $q3 = 1.26171219808761642112e-01 + $s * 1.36370839120290507362e-02;
            $p4 = -2.16637559486879084300e-03;
            $q4 = 1.19844998467991074170e-02;
            $p = $p1 + $s2 * $p2 + $s4 * $p3 + $s6 * $p4;
            $q = $q1 + $s2 * $q2 + $s4 * $q3 + $s6 * $q4;
            $erx = 8.45062911510467529297e-01;

            return $hx >= 0 ? $erx + $p / $q : -$erx - $p / $q;
        }

        if ($ix >= 0x40180000) { // |x| >= 6
            return $hx >= 0 ? 1.0 - 1e-300 : 1e-300 - 1.0;
        }

        $x = abs($x);
        $s = 1.0 / ($x * $x);

        if ($ix < 0x4006DB6E) { // |x| < 1/0.35
            $r1 = -9.86494403484714822705e-03 + $s * -6.93858572707181764372e-01;
            $s2 = $s * $s;
            $q1 = 1.0 + $s * 1.96512716674392571292e+01;
            $s4 = $s2 * $s2;
            $r2 = -1.05586262253232909814e+01 + $s * -6.23753324503260060396e+01;
            $s6 = $s4 * $s2;
            $q2 = 1.37657754143519042600e+02 + $s * 4.34565877475229228821e+02;
            $s8 = $s4 * $s4;
            $r3 = -1.62396669462573470355e+02 + $s * -1.84605092906711035994e+02;
            $q3 = 6.45387271733267880336e+02 + $s * 4.29008140027567833386e+02;
            $r4 = -8.12874355063065934246e+01 + $s * -9.81432934416914548592e+00;
            $q4 = 1.08635005541779435134e+02 + $s * 6.57024977031928170135e+00;
            $big = $r1 + $s2 * $r2 + $s4 * $r3 + $s6 * $r4;
            $div = $q1 + $s2 * $q2 + $s4 * $q3 + $s6 * $q4 + $s8 * -6.04244152148580987438e-02;
        } else { // |x| >= 1/0.35
            $r1 = -9.86494292470009928597e-03 + $s * -7.99283237680523006574e-01;
            $s2 = $s * $s;
            $q1 = 1.0 + $s * 3.03380607434824582924e+01;
            $s4 = $s2 * $s2;
            $r2 = -1.77579549177547519889e+01 + $s * -1.60636384855821916062e+02;
            $s6 = $s4 * $s2;
            $q2 = 3.25792512996573918826e+02 + $s * 1.53672958608443695994e+03;
            $r3 = -6.37566443368389627722e+02 + $s * -1.02509513161107724954e+03;
            $q3 = 3.19985821950859553908e+03 + $s * 2.55305040643316442583e+03;
            $q4 = 4.74528541206955367215e+02 + $s * -2.24409524465858183362e+01;
            $big = $r1 + $s2 * $r2 + $s4 * $r3 + $s6 * -4.83519191608651397019e+02;
            $div = $q1 + $s2 * $q2 + $s4 * $q3 + $s6 * $q4;
        }

        $z = self::clearLowWord($x);
        $r = exp(-$z * $z - 0.5625) * exp(($z - $x) * ($z + $x) + $big / $div);

        return $hx >= 0 ? 1.0 - $r / $x : $r / $x - 1.0;
    }

    /**
     * Number of series at `$t`.
     */
    public function count(int|float $t): int
    {
        return iterator_count($this->series($t));
    }

    /**
     * @return Generator<int, array{0: string, 1: string, 2: int|float}>
     */
    private function batches(int|float $t): Generator
    {
        $legacy = $this->schema === self::LEGACY;

        foreach (self::BATCH_KINDS as $k => $kind) {
            $queue = $this->queues[$k % count($this->queues)];
            yield ['queue_batch_info', $this->labels(['batch' => $kind, 'queue' => $queue, 'connection' => self::CONNECTION]), 1];

            foreach ($this->batchClasses[$kind] as $class) {
                yield ['queue_batch_job_classes', $this->labels(['batch' => $kind, 'queue' => $queue, 'job_class' => $class]), 1];
            }

            $start = (int) floor($t / 600) * 600 + ($k * 37) % 600;
            $running = 0;
            $oldest = 0;
            $jobs = ['total' => 0, 'pending' => 0, 'failed' => 0];

            for ($n = 0; $n < 8; $n++) {
                $created = $start - $n * 600;

                if ($created > $t) {
                    continue;
                }

                $closed = $created + 360;

                if ($closed < $t - 3600) {
                    break;
                }

                $id = sprintf('%012x', $this->h('b', $kind, $created)).'-batch';
                $name = $kind.'_'.$created;
                $total = 200 + $this->h('n', $id) % 800;
                $done = $t >= $closed ? $total : (int) ($total * ($t - $created) / 360);
                $failed = $done !== 0 ? $this->h('f', $id) % 3 : 0;
                $status = $t < $closed ? 'Running' : ($failed !== 0 ? 'Failures' : 'Finished');

                foreach (['total' => $total, 'pending' => $total - $done + $failed, 'failed' => $failed] as $state => $value) {
                    yield ['queue_batch_job_counts', $this->labels(['batch' => $kind, 'name' => $name, 'state' => $state]), $value];
                }

                yield ['queue_batch_created_timestamp_seconds', $this->labels(['batch' => $kind, 'name' => $name]), $created];

                if ($t >= $closed) {
                    yield ['queue_batch_closed_timestamp_seconds', $this->labels(['batch' => $kind, 'name' => $name]), $closed];
                } else {
                    $running++;
                    $oldest = max($oldest, $t - $created);
                    $jobs['total'] += $total;
                    $jobs['pending'] += $total - $done + $failed;
                    $jobs['failed'] += $failed;
                }

                $progress = $legacy
                    ? ['batch' => $kind, 'name' => $name, 'status' => $status, 'size' => (string) $total, 'created' => (string) $created, 'id' => $id]
                    : ['batch' => $kind, 'name' => $name, 'status' => $status, 'id' => $id];
                yield ['queue_batch_progress', $this->labels($progress), (int) (100 * $done / $total)];
            }

            yield ['queue_batches_running', $this->labels(['batch' => $kind]), $running];

            foreach ($jobs as $state => $value) {
                yield ['queue_batch_jobs', $this->labels(['batch' => $kind, 'state' => $state]), $value];
            }

            if ($running !== 0) {
                yield ['queue_batch_oldest_age_seconds', $this->labels(['batch' => $kind]), (int) $oldest];
            }
        }
    }

    private function build(): void
    {
        if ($this->profile === 'medium') {
            $this->supervisors = [
                'supervisor-default' => ['queues' => ['default', 'notifications', 'emails'], 'min' => 1, 'max' => 12, 'balance' => 'auto'],
                'supervisor-slow' => ['queues' => ['slow', 'reports', 'exports'], 'min' => 1, 'max' => 6, 'balance' => 'auto'],
                'supervisor-high' => ['queues' => ['high', 'payments'], 'min' => 2, 'max' => 10, 'balance' => 'simple'],
                'supervisor-sync' => ['queues' => ['sync', 'imports', 'webhooks', 'crm'], 'min' => 1, 'max' => 8, 'balance' => 'auto'],
            ];
            $this->waits = ['default' => 60, 'notifications' => 60, 'emails' => 120, 'slow' => 300, 'reports' => 600, 'exports' => 600,
                'high' => 10, 'payments' => 10, 'sync' => 60, 'imports' => 300, 'webhooks' => 30, 'crm' => 120];
            $classCount = 160;
        } else {
            $this->supervisors = [];
            $this->waits = [];
            $bases = ['default', 'high', 'low', 'mail', 'sms', 'slow', 'reports', 'exports', 'imports', 'sync'];
            $waits = [60, 10, 300, 120, 30, 300, 600, 600, 300, 60];

            foreach ($bases as $s => $base) {
                $queues = [$base, $base.'_2', $base.'_3', $base.'_4'];
                $this->supervisors['supervisor-'.$base] = ['queues' => $queues, 'min' => 1 + $s % 2, 'max' => 4 + 2 * ($s % 5), 'balance' => $s % 3 === 0 ? 'simple' : 'auto'];

                foreach ($queues as $queue) {
                    $this->waits[$queue] = $waits[$s];
                }
            }

            $classCount = 500;
        }

        $this->queues = [];

        foreach ($this->supervisors as $settings) {
            $this->queues = [...$this->queues, ...$settings['queues']];
        }

        $this->les = [];

        foreach (self::BOUNDS as $bound) {
            $this->les[] = is_int($bound) ? (string) $bound : self::format($bound);
        }

        $this->les[] = '+Inf';
        $this->classes = [];

        for ($i = 0; $i < $classCount; $i++) {
            $domain = self::DOMAINS[$i % count(self::DOMAINS)];
            $verb = self::VERBS[($i * 7) % count(self::VERBS)];
            $this->classes[] = sprintf('App\\Jobs\\%s\\%s%sJob%03d', $domain, $verb, $domain, $i);
        }

        foreach ($this->classes as $i => $class) {
            $queue = $this->queues[$this->h('q', $class) % count($this->queues)];
            $rate = 0.0005 + ($this->u('r', $class) ** 3) * 0.6;
            $mean = 0.05 + ($this->u('d', $class) ** 2) * (in_array($queue, self::SLOW_QUEUES, true) ? 40 : 4);
            $this->addTuple($queue, $class, $rate, $mean);

            if ($i % 8 === 0) {
                $second = $this->queues[(array_search($queue, $this->queues, true) + 1) % count($this->queues)];
                $this->addTuple($second, $class, $rate / 4, $mean);
            }
        }

        foreach ($this->queues as $queue) {
            $shares = [];

            foreach (self::BOUNDS as $bound) {
                $shares[] = min(1.0, $this->lognormCdf($bound, 2.0 + ($this->h($queue) % 5)));
            }

            $shares[] = 1.0;
            $this->waitShares[$queue] = $shares;
        }

        foreach (self::BATCH_KINDS as $k => $kind) {
            $queue = $this->queues[$k % count($this->queues)];
            $classes = [];

            foreach ($this->tuples as $tuple) {
                if ($tuple['queue'] === $queue && count($classes) < 3) {
                    $classes[] = $tuple['class'];
                }
            }

            $this->batchClasses[$kind] = $classes;
        }
    }

    private function addTuple(string $queue, string $class, float $rate, float $mean): void
    {
        $shares = [];

        foreach (self::BOUNDS as $bound) {
            $shares[] = $this->lognormCdf($bound, $mean);
        }

        $shares[] = 1.0;
        $this->tuples[] = [
            'queue' => $queue,
            'class' => $class,
            'rate' => $rate,
            'mean' => $mean,
            'base' => $this->labels(['connection' => self::CONNECTION, 'queue' => $queue, 'job_class' => $class]),
            'shares' => $shares,
        ];
    }

    /**
     * The probe's hash: the first 48 bits of md5("a|b|...").
     */
    private function h(string|int ...$parts): int
    {
        $key = implode('|', $parts);

        return $this->hashes[$key] ??= (int) hexdec(substr(md5($key), 0, 12));
    }

    private function u(string|int ...$parts): float
    {
        return $this->h(...$parts) / 16 ** 12;
    }

    private function spike(int|float $t, int $period, int $width, string $key): float
    {
        $phase = $this->mod($t + $this->u('phase', $key) * $period, $period);

        return $phase < $width ? max(0.0, 1 - $phase / $width) : 0.0;
    }

    /**
     * Integral of a * (1 + 0.6 sin(2 pi t / DAY)) from 0 to t.
     */
    private function diurnalIntegral(float $a, int|float $t): float
    {
        return $a * ($t - 0.6 * self::DAY / (2 * M_PI) * (cos(2 * M_PI * $t / self::DAY) - 1));
    }

    private function rateAt(float $a, int|float $t): float
    {
        return $a * (1 + 0.6 * sin(2 * M_PI * $t / self::DAY));
    }

    private function lognormCdf(int|float $x, float $mean): float
    {
        $sigma = 0.8;
        $mu = log($mean) - $sigma * $sigma / 2;

        return 0.5 * (1 + self::erf((log($x) - $mu) / ($sigma * sqrt(2))));
    }

    /**
     * Python's modulo: the sign of the divisor (fmod for floats).
     */
    private function mod(int|float $value, int $divisor): int|float
    {
        if (is_int($value)) {
            $mod = $value % $divisor;

            return $mod < 0 ? $mod + $divisor : $mod;
        }

        $mod = fmod($value, $divisor);

        return $mod < 0 ? $mod + $divisor : $mod;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function labels(array $labels): string
    {
        $pairs = [];

        foreach ($labels as $name => $value) {
            $pairs[] = $name.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return implode(',', $pairs);
    }

    /**
     * The high 32 bits of an IEEE 754 double, signed (fdlibm GET_HIGH_WORD).
     */
    private static function highWord(float $x): int
    {
        $words = unpack('V', substr(pack('e', $x), 4, 4));
        $high = is_array($words) && is_int($words[1] ?? null) ? $words[1] : 0;

        return $high >= 0x80000000 ? $high - 0x100000000 : $high;
    }

    /**
     * The double with its low 32 bits cleared (fdlibm SET_LOW_WORD(x, 0)).
     */
    private static function clearLowWord(float $x): float
    {
        $value = unpack('e', "\0\0\0\0".substr(pack('e', $x), 4, 4));

        return is_array($value) && is_float($value[1] ?? null) ? $value[1] : $x;
    }
}
