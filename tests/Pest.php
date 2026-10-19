<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Integration', 'Feature', 'Grafana', 'Contract', 'Truth');
