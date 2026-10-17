<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

use Closure;
use DF\HorizonObservability\Batches\BatchReader;
use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Collectors\BatchesCollector;
use DF\HorizonObservability\Collectors\Collector;
use DF\HorizonObservability\Collectors\HorizonCollector;
use DF\HorizonObservability\Collectors\JobClassesCollector;
use DF\HorizonObservability\Collectors\QueueCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Support\Versions;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;

/**
 * Ordered, isolated, budgeted run of the collectors plus the self-metrics and the namespace prefix (Arch Q1.3, Q1.4).
 *
 * One scrape per request; nothing is resolved at boot and nothing is memoised between scrapes, so every value is
 * read and rendered afresh (C3: counters are absolute, there is no registry).
 *
 * - **Order.** `horizon`, `queues`, `attempts`, `job_classes`, `batches` ({@see Config::COLLECTORS}): when the budget
 *   runs out, the least valuable data is dropped first.
 * - **Enabled.** A disabled collector, and `batches` in auto mode (`collectors.batches = null`) without the database
 *   batching driver or its table ({@see BatchReader::available()}), is not run and has no self-metric.
 * - **Budget.** `min(8 s, max(1 s, X-Prometheus-Scrape-Timeout-Seconds - 0.5 s))`, 9 s when the header is missing or
 *   not a number. A collector that would start after the deadline is skipped with success 0 and duration 0, and a
 *   throttled warning. Collectors check {@see ScrapeContext::deadlineExceeded()} themselves between expensive steps.
 * - **Isolation.** All of a collector's families or none: any `Throwable` while resolving, collecting, validating or
 *   rendering it (including `ErrorException` and {@see InvalidMetric}) drops its families, sets success 0 and is
 *   logged through the throttled {@see FailureReporter}, never `report()`. A failure reported with
 *   {@see ScrapeContext::reportFailure()} keeps the data but sets success 0. A collector may only yield the families
 *   the catalog assigns to it ({@see Metric::collector()}), each once.
 * - **Self-metrics**, rendered last: `exporter_collector_success` and `exporter_collector_duration_seconds` per run
 *   or skipped collector, `exporter_build_info` and `exporter_config_problems`. The live push series belong to the
 *   `attempts` collector (Addendum B', R4.11).
 *
 * The response is 200 whatever happens here (Arch Q1.3): `up` means "reachable and authorised", data health is in the
 * self-metrics.
 *
 * @internal
 */
final readonly class Exporter
{
    /**
     * Upper bound of the scrape budget, seconds (Arch Q3 internal constants).
     */
    public const BUDGET = 8.0;

    /**
     * Lower bound of the scrape budget, seconds.
     */
    public const MIN_BUDGET = 1.0;

    /**
     * Seconds kept free for rendering and the response below Prometheus' scrape timeout.
     */
    public const TIMEOUT_MARGIN = 0.5;

    /**
     * Scrape timeout assumed when Prometheus sends no (usable) timeout header, seconds.
     */
    public const DEFAULT_SCRAPE_TIMEOUT = 9.0;

    public const TIMEOUT_HEADER = 'X-Prometheus-Scrape-Timeout-Seconds';

    /**
     * Collector classes by id, in {@see Config::COLLECTORS} order.
     *
     * @var array<string, class-string<Collector>>
     */
    public const COLLECTORS = [
        'horizon' => HorizonCollector::class,
        'queues' => QueueCollector::class,
        'attempts' => AttemptsCollector::class,
        'job_classes' => JobClassesCollector::class,
        'batches' => BatchesCollector::class,
    ];

    /**
     * Fallback for an unknown version in `exporter_build_info` (label values are never empty).
     */
    public const UNKNOWN_VERSION = 'unknown';

    /**
     * @var Closure(): float
     */
    private Closure $clock;

    private TextFormat $format;

    /**
     * @param  (Closure(): float)|null  $clock  monotonic seconds for the budget and the durations, for tests; default
     *                                          `hrtime()`
     * @param  array<string, Collector>  $collectors  collectors by id that replace the container's, for tests
     */
    public function __construct(
        private Container $container,
        ?Closure $clock = null,
        private array $collectors = [],
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->format = new TextFormat;
    }

    /**
     * The text exposition of one scrape.
     */
    public function scrape(Request $request): string
    {
        /** @var Config $config */
        $config = $this->container->make(Config::class);
        /** @var FailureReporter $reporter */
        $reporter = $this->container->make(FailureReporter::class);
        // The provider logs config problems in the console only; over HTTP this authorised scrape does (Arch Q2.4).
        $reporter->configProblems($config);

        $budget = self::budget($request);
        $context = new ScrapeContext(now()->toImmutable(), $budget, $this->clock);
        $prefix = $config->prefix();
        $out = '';

        /** @var array<string, array{0: int, 1: float}> $status collector id => [success, seconds] */
        $status = [];

        foreach (Config::COLLECTORS as $id) {
            $started = ($this->clock)();

            try {
                if (! $this->enabled($id, $config)) {
                    continue;
                }
            } catch (Throwable $e) {
                $reporter->report('collector:'.$id, $e);
                $status[$id] = [0, ($this->clock)() - $started];

                continue;
            }

            if ($context->deadlineExceeded()) {
                $status[$id] = [0, 0.0];
                $reporter->warnOnce('budget:'.$id, sprintf(
                    'the scrape budget of %s s ran out before the %s collector, which was skipped (exporter_collector_success 0); raise the scrape_timeout or check the collector durations.',
                    TextFormat::number($budget),
                    $id,
                ));

                continue;
            }

            $started = ($this->clock)();

            try {
                $out .= $this->format->render($this->families($id, $context), $prefix);
                $failures = $context->takeFailures();

                foreach ($failures as $failure) {
                    $reporter->report('collector:'.$id.':'.$failure['source'], $failure['exception']);
                }

                $success = $failures === [] ? 1 : 0;
            } catch (Throwable $e) {
                $context->takeFailures();
                $reporter->report('collector:'.$id, $e);
                $success = 0;
            }

            $status[$id] = [$success, ($this->clock)() - $started];
        }

        return $out.$this->format->render($this->selfMetrics($status, $config, $reporter), $prefix);
    }

    /**
     * The scrape budget in seconds for the request's `X-Prometheus-Scrape-Timeout-Seconds` header.
     */
    public static function budget(Request $request): float
    {
        $header = $request->headers->get(self::TIMEOUT_HEADER);
        $header = is_string($header) ? trim($header) : '';
        $timeout = is_numeric($header) ? (float) $header : self::DEFAULT_SCRAPE_TIMEOUT;

        if (! is_finite($timeout)) {
            $timeout = self::DEFAULT_SCRAPE_TIMEOUT;
        }

        return min(self::BUDGET, max(self::MIN_BUDGET, $timeout - self::TIMEOUT_MARGIN));
    }

    /**
     * Whether the collector runs: its `collectors.*` switch, and for `batches` in auto mode the batching detection.
     */
    private function enabled(string $id, Config $config): bool
    {
        if (! array_key_exists($id, $config->collectors)) {
            return false;
        }

        $enabled = $config->collectors[$id];

        if ($enabled !== null) {
            return $enabled;
        }

        /** @var BatchReader $reader */
        $reader = $this->container->make(BatchReader::class);

        return $reader->available();
    }

    /**
     * The collector's families, fully materialised and checked against the catalog.
     *
     * @return list<MetricFamily>
     */
    private function families(string $id, ScrapeContext $context): array
    {
        $families = [];

        foreach ($this->collector($id)->collect($context) as $family) {
            if (! $family instanceof MetricFamily) {
                throw new InvalidMetric(sprintf('The %s collector yielded %s instead of a MetricFamily.', $id, get_debug_type($family)));
            }

            $metric = $family->metric();

            if ($metric->collector() !== $id) {
                throw new InvalidMetric(sprintf('The %s collector yielded %s, a family of the %s collector.', $id, $metric->value, $metric->collector()));
            }

            if (isset($families[$metric->value])) {
                throw new InvalidMetric(sprintf('The %s collector yielded %s twice; build each family once.', $id, $metric->value));
            }

            $families[$metric->value] = $family;
        }

        return array_values($families);
    }

    private function collector(string $id): Collector
    {
        if (isset($this->collectors[$id])) {
            return $this->collectors[$id];
        }

        $class = self::COLLECTORS[$id] ?? throw new InvalidMetric(sprintf('Unknown collector [%s].', $id));
        $collector = $this->container->make($class);

        if (! $collector instanceof Collector) {
            throw new InvalidMetric(sprintf('[%s] does not implement %s.', $class, Collector::class));
        }

        return $collector;
    }

    /**
     * @param  array<string, array{0: int, 1: float}>  $status
     * @return list<MetricFamily>
     */
    private function selfMetrics(array $status, Config $config, FailureReporter $reporter): array
    {
        $success = MetricFamily::of(Metric::CollectorSuccess);
        $duration = MetricFamily::of(Metric::CollectorDuration);

        foreach ($status as $id => [$ok, $seconds]) {
            $success->set(['collector' => $id], $ok);
            $duration->set(['collector' => $id], round(max(0.0, $seconds), 6));
        }

        $families = [$success, $duration];

        try {
            $families[] = MetricFamily::of(Metric::BuildInfo)->set($this->buildInfo(), 1);
        } catch (Throwable $e) {
            $reporter->report('build_info', $e);
        }

        $families[] = MetricFamily::of(Metric::ConfigProblems)->set([], count($config->problems));

        return $families;
    }

    /**
     * @return array<string, string>
     */
    private function buildInfo(): array
    {
        /** @var Versions $versions */
        $versions = $this->container->make(Versions::class);

        return [
            'version' => $versions->package() ?? self::UNKNOWN_VERSION,
            'php' => $versions->php(),
            'laravel' => $versions->laravel(),
            'horizon' => $versions->horizon() ?? self::UNKNOWN_VERSION,
            'redis_client' => $versions->redisClient(),
        ];
    }
}
