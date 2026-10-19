<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Attempts\JobClassLabel;
use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;

/**
 * Collector `job_classes`: queue_job_classes, one series per queueable class of the application code, value 1, so
 * the dashboard also lists classes that never ran.
 *
 * The classes come from {@see JobClassCache} (the deploy-time file, else the runtime cache, else live discovery), so
 * a scrape costs a cache file read. Labels pass through {@see JobClassLabel::normalize()}, the
 * normaliser of every other `job_class` label, so the values join with the attempt and queue series.
 *
 * @internal
 */
final readonly class JobClassesCollector implements Collector
{
    public function __construct(private JobClassCache $cache) {}

    public function name(): string
    {
        return 'job_classes';
    }

    public function collect(ScrapeContext $context): iterable
    {
        $family = MetricFamily::of(Metric::JobClasses);
        $seen = [];

        foreach ($this->cache->classes() as $class) {
            $label = JobClassLabel::normalize($class);

            if (! isset($seen[$label])) {
                $seen[$label] = true;
                $family->set(['job_class' => $label], 1);
            }
        }

        return [$family];
    }
}
