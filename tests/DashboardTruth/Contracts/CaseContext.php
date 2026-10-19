<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\SeriesStore;
use LogicException;

/**
 * Everything an expectation may read for one observed case on one Grafana version (design section 7.1): the case,
 * the dataset's manifest and stored samples, the period [from, to] the frontend sent, and the variables as the scene
 * resolved them (value, text and options). Never the dashboard's PromQL.
 */
final readonly class CaseContext
{
    /**
     * @param  array<string, array{value: mixed, text: mixed, options: list<array{text: mixed, value: mixed}>}>  $variables
     */
    public function __construct(
        public CaseDef $case,
        public string $grafana,
        public Manifest $manifest,
        public SeriesStore $store,
        public int $fromMs,
        public int $toMs,
        public array $variables,
    ) {}

    /**
     * S in seconds, from the scene's `scrape_interval` (the textbox default, or the case's URL override).
     */
    public function scrapeInterval(): int
    {
        $value = $this->variables['scrape_interval']['value'] ?? null;

        if (! is_string($value) || $value === '') {
            throw new LogicException('The scene has no scrape_interval value.');
        }

        return intdiv(Durations::parse($value), 1000);
    }

    /**
     * The values a filter variable selects, or null for All.
     *
     * @return list<string>|null
     */
    public function selected(string $variable): ?array
    {
        $value = $this->variables[$variable]['value'] ?? '$__all';
        $values = array_values(array_map(strval(...), is_array($value) ? $value : [$value]));

        return $values === [] || in_array('$__all', $values, true) ? null : $values;
    }
}
