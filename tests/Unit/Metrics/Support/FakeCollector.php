<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Unit\Metrics\Support;

use Closure;
use DF\HorizonObservability\Collectors\Collector;
use DF\HorizonObservability\Config\Config;
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

    /**
     * One collector per id that collects nothing, so a test does not depend on the real collectors (other tasks'
     * code, which may need Redis or the database). Override single ids with `[...FakeCollector::quiet(), ...]`.
     *
     * @return array<string, self>
     */
    public static function quiet(): array
    {
        $collectors = [];

        foreach (Config::COLLECTORS as $id) {
            $collectors[$id] = new self($id, static fn (): array => []);
        }

        return $collectors;
    }

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
