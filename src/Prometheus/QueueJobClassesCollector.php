<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Prometheus;

use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;
use SushiMarket\HorizonPrometheusGrafana\QueuedJobClasses;

/**
 * Все классы очередей из кода, в том числе ни разу не выполнявшиеся: по остальным метрикам
 * такой класс не виден. Без метки queue — очередь часто задаётся только при отправке (onQueue).
 */
class QueueJobClassesCollector implements Collector
{
    public function register(): void
    {
        Prometheus::addGauge('Queue job classes')
            ->name('queue_job_classes')
            ->helpText('Классы из кода, которые выполняются в очереди: 1 на класс')
            ->label('job_class')
            ->value(fn (): array => app(ScrapeCache::class)->remember(self::class, fn (): array => array_map(
                fn (string $class): array => [1, [$class]],
                app(QueuedJobClasses::class)->all(),
            )));
    }
}
