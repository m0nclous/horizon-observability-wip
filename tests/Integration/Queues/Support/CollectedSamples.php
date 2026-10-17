<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Integration\Queues\Support;

use DF\HorizonObservability\Collectors\Collector;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Tests\Support\Exposition;
use InvalidArgumentException;
use Throwable;

/**
 * What one collector run produced, for assertions on the horizon and queues collectors without the exporter: the
 * families in yield order, the samples by series name (family plus suffix, without the namespace) and labels key
 * ({@see Exposition::key()}), and the partial failures the collector reported.
 */
final readonly class CollectedSamples
{
    /**
     * @param  list<string>  $families
     * @param  array<string, array<string, int|float>>  $samples
     * @param  list<array{source: string, exception: Throwable}>  $failures
     */
    private function __construct(public array $families, public array $samples, public array $failures) {}

    public static function collect(Collector $collector, ScrapeContext $context): self
    {
        $families = [];
        $samples = [];

        foreach ($collector->collect($context) as $family) {
            if (! $family instanceof MetricFamily) {
                throw new InvalidArgumentException('The collector yielded '.get_debug_type($family).'.');
            }

            $families[] = $family->metric()->value;

            foreach ($family->samples() as $sample) {
                $samples[$family->metric()->value.$sample->suffix][Exposition::key($sample->labels)] = $sample->value;
            }
        }

        return new self($families, $samples, $context->takeFailures());
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function value(string $name, array $labels = []): int|float|null
    {
        return $this->samples[$name][Exposition::key($labels)] ?? null;
    }

    /**
     * @return array<string, int|float>
     */
    public function series(string $name): array
    {
        return $this->samples[$name] ?? [];
    }

    /**
     * The sources of the reported failures, in order.
     *
     * @return list<string>
     */
    public function failedSources(): array
    {
        return array_map(static fn (array $failure): string => $failure['source'], $this->failures);
    }
}
