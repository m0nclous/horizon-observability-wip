<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

/**
 * The category of a failure (design section 3): only a dashboard defect is ever a finding against T13.
 *
 * - exporter mismatch: the recorded scrape disagrees with T16's model (an L1 failure);
 * - dashboard defect: a panel value disagrees with its expectation (an L2 failure);
 * - infrastructure: the stack, a timeout, an unattributed request or an unanswered target;
 * - case setup: a URL value that is not an option of its variable, or a `$` left in a query.
 */
enum Category: string
{
    case ExporterMismatch = 'exporter mismatch';
    case DashboardDefect = 'dashboard defect';
    case Infrastructure = 'infrastructure';
    case CaseSetup = 'case setup';
}
