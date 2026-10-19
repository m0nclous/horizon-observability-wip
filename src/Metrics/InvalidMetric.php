<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

use LogicException;

/**
 * A collector built an invalid family (label set mismatch, duplicate series, bad histogram shape). The exporter treats
 * it as a failure of that collector.
 *
 * @internal
 */
final class InvalidMetric extends LogicException {}
