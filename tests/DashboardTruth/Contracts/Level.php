<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

/**
 * Where an expectation is compared (design sections 3 and 10): the frames Grafana's backend returned for a target
 * (`/api/ds/query`), the panel data after the panel's transformations (the scene), or the DOM a user sees.
 */
enum Level: string
{
    case Frame = 'frame';
    case Transformed = 'transformed';
    case Dom = 'dom';
}
