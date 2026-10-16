<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Prometheus;

use Closure;
use Illuminate\Support\Facades\Log;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;
use SushiMarket\HorizonPrometheusGrafana\QueueJobMetricsStorage;

/**
 * Попытки выполнения джобов, которые воркеры пишут через QueueJobMetricsSubscriber.
 *
 * Счётчики публикуются абсолютными значениями из Redis через inc(): реестр spatie
 * создаётся заново на каждый сбор (config prometheus.cache = null). Перед первым чтением
 * за сбор хранилище сверяет run_id Redis и после отката обнуляет счётчики.
 */
class QueueJobsCollector implements Collector
{
    private const QUEUE_LABELS = ['connection', 'queue'];
    private const JOB_LABELS = ['connection', 'queue', 'job_class'];

    public function __construct(private readonly QueueJobMetricsStorage $storage)
    {
    }

    public function register(): void
    {
        Prometheus::addCounter('Queue job attempts')
            ->name('queue_jobs_total')
            ->helpText('Попытки выполнения джобов по итогу: completed, released, retried, failed, timed_out')
            ->labels([...self::JOB_LABELS, 'status'])
            ->inc(fn (): array => $this->read('jobs_total', fn (): array => $this->storage->jobsTotal()));

        Prometheus::addGauge('Queue job duration min')
            ->name('queue_job_duration_min_seconds')
            ->helpText('Самая короткая попытка класса за текущую и прошлую минуту, секунды')
            ->labels(self::JOB_LABELS)
            ->value(fn (): array => $this->durationExtremes()['min'] ?? []);

        Prometheus::addGauge('Queue job duration max')
            ->name('queue_job_duration_max_seconds')
            ->helpText('Самая долгая попытка класса за текущую и прошлую минуту, секунды')
            ->labels(self::JOB_LABELS)
            ->value(fn (): array => $this->durationExtremes()['max'] ?? []);

        $this->addHistogram(
            'queue_job_duration_seconds',
            'Длительность попытки выполнения джоба, секунды',
            self::JOB_LABELS,
            fn (): array => $this->storage->durationHistogram(),
        );

        $this->addHistogram(
            'queue_job_wait_seconds',
            'Сколько первая попытка джоба ждала свободного воркера, секунды',
            self::QUEUE_LABELS,
            fn (): array => $this->storage->waitHistogram(),
        );
    }

    /**
     * spatie/laravel-prometheus не умеет отдавать готовые гистограммы, поэтому bucket/sum/count
     * публикуются отдельными счётчиками с суффиксами гистограммы: histogram_quantile()
     * работает с ними как с обычной гистограммой.
     *
     * @param list<string> $labels
     * @param Closure(): array{buckets: list<array>, sums: list<array>, counts: list<array>} $read
     */
    private function addHistogram(string $name, string $help, array $labels, Closure $read): void
    {
        $part = fn (string $part): array => $this->read($name, $read)[$part] ?? [];

        Prometheus::addCounter($name . ' bucket')
            ->name($name . '_bucket')
            ->helpText($help)
            ->labels([...$labels, 'le'])
            ->inc(fn (): array => $part('buckets'));

        Prometheus::addCounter($name . ' sum')
            ->name($name . '_sum')
            ->helpText($help . ': сумма')
            ->labels($labels)
            ->inc(fn (): array => $part('sums'));

        Prometheus::addCounter($name . ' count')
            ->name($name . '_count')
            ->helpText($help . ': число замеров')
            ->labels($labels)
            ->inc(fn (): array => $part('counts'));
    }

    private function durationExtremes(): array
    {
        return $this->read('duration_extremes', fn (): array => $this->storage->durationExtremes());
    }

    private function read(string $source, Closure $read): array
    {
        $cache = app(ScrapeCache::class);

        // Раз за сбор и до первого чтения: после отката Redis счётчики обнуляются
        $cache->remember(self::class . ':redis_restart', function (): array {
            if ($this->storage->resetCountersAfterRedisRestart()) {
                Log::info('QueueMetrics: сменился run_id Redis, счётчики попыток джобов обнулены');
            }

            return [];
        });

        return $cache->remember(self::class . ':' . $source, $read);
    }
}
