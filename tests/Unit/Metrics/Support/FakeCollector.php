<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Unit\Metrics\Support;

use Closure;
use DF\HorizonObservability\Collectors\Collector;
use DF\HorizonObservability\Metrics\ScrapeContext;

/**
 * A collector whose families come from a closure; counts its runs and keeps the last scrape context.
 */
final class FakeCollector implements Collector
{
    public int $runs = 0;

    public ?ScrapeContext $context = null;

    /**
     * @param  Closure(ScrapeContext): iterable<mixed>  $collect
     */
    public function __construct(private readonly string $name, private readonly Closure $collect) {}

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return iterable<mixed>
     */
    public function collect(ScrapeContext $context): iterable
    {
        $this->runs++;
        $this->context = $context;

        return ($this->collect)($context);
    }
}
