<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

/**
 * The specification of one dashboard panel: its targets and its DOM (design section 13, Expect/Panels). Registered in
 * Expect/Specs.
 */
interface PanelSpec
{
    public function panel(): int;

    /**
     * @return list<TargetSpec>
     */
    public function targets(): array;

    public function dom(): ?DomSpec;
}
