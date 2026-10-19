<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

/**
 * The three renders of the dashboard (`--live=off|poll|stream`, owner decision 9): off and stream are the fixtures in
 * tests/Fixtures/grafana, poll is the committed package render in resources/grafana. T17's own enum, so the
 * expectation engine never depends on the renderer's classes (design section 7.1).
 */
enum Mode: string
{
    case Off = 'off';
    case Poll = 'poll';
    case Stream = 'stream';

    /**
     * The dashboard uid of this render on the stack's Grafanas: the render imported with only uid and id changed.
     */
    public function uid(): string
    {
        return 't17-'.$this->value;
    }
}
