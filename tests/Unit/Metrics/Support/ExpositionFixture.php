<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Unit\Metrics\Support;

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Labels;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Support\Versions;
use Illuminate\Config\Repository;

/**
 * A full, deterministic scrape for the golden exposition (`tests/Fixtures/exposition/golden.txt`): fake collectors
 * that yield every family of the catalog with realistic values and fixed durations, and fixed versions.
 */
final class ExpositionFixture
{
    public const GOLDEN = __DIR__.'/../../../Fixtures/exposition/golden.txt';

    /**
     * Value of the `php` label in the golden file; {@see self::normalize()} puts it in place of `PHP_VERSION`.
     */
    public const PHP = 'PHP_VERSION';

    /**
     * Seconds each fake collector takes on the fake clock.
     */
    public const DURATIONS = ['horizon' => 0.0125, 'queues' => 0.25, 'attempts' => 0.0375, 'job_classes' => 0.0005, 'batches' => 0.125];

    /**
     * @return array<string, FakeCollector>
     */
    public static function collectors(FakeClock $clock): array
    {
        $families = [
            'horizon' => self::horizon(...),
            'queues' => self::queues(...),
            'attempts' => self::attempts(...),
            'job_classes' => self::jobClasses(...),
            'batches' => self::batches(...),
        ];
        $collectors = [];

        foreach ($families as $id => $build) {
            $collectors[$id] = new FakeCollector($id, static function () use ($id, $build, $clock): array {
                $clock->advance(self::DURATIONS[$id]);

                return $build();
            });
        }

        return $collectors;
    }

    /**
     * Versions with fixed package, Laravel, Horizon and Redis client versions (the PHP version is the runtime's).
     */
    public static function versions(): Versions
    {
        $pretty = ['sushi-market/horizon-observability' => '0.1.0', 'laravel/horizon' => 'v5.50.1', 'predis/predis' => 'v3.0.0'];

        return new Versions(
            new Repository(['database' => ['redis' => ['client' => 'predis']]]),
            '12.40.0',
            static fn (string $package): ?string => isset($pretty[$package]) ? ltrim($pretty[$package], 'v').'.0' : null,
            static fn (string $package): ?string => $pretty[$package] ?? null,
        );
    }

    /**
     * The exposition with the runtime PHP version replaced by {@see self::PHP}.
     */
    public static function normalize(string $text): string
    {
        return str_replace('php="'.PHP_VERSION.'"', 'php="'.self::PHP.'"', $text);
    }

    /**
     * @return list<MetricFamily>
     */
    private static function horizon(): array
    {
        $supervisors = [
            ['supervisor-default', 'auto', 1, 3, ['default' => 3], ['default' => 'default']],
            ['supervisor-pool', 'off', 1, 2, ['mail,sms' => 2], ['mail' => 'mail,sms', 'sms' => 'mail,sms']],
            ['supervisor-slow', 'auto', 1, 2, ['slow' => 1, 'reports' => 1], ['slow' => 'slow', 'reports' => 'reports']],
        ];
        $processes = MetricFamily::of(Metric::SupervisorProcesses);
        $min = MetricFamily::of(Metric::SupervisorMinProcesses);
        $max = MetricFamily::of(Metric::SupervisorMaxProcesses);
        $info = MetricFamily::of(Metric::SupervisorInfo);
        $pools = MetricFamily::of(Metric::SupervisorQueues);

        foreach ($supervisors as [$name, $balance, $minimum, $maximum, $queues, $poolOf]) {
            foreach ($queues as $queue => $count) {
                $processes->set(['supervisor' => $name, 'queue' => $queue], $count);
            }

            $min->set(['supervisor' => $name], $minimum);
            $max->set(['supervisor' => $name], $maximum);
            $info->set(['supervisor' => $name, 'balance' => $balance, 'connection' => 'redis'], 1);

            foreach ($poolOf as $queue => $pool) {
                $pools->set(['supervisor' => $name, 'queue' => $queue, 'pool' => $pool], 1);
            }
        }

        return [
            MetricFamily::of(Metric::HorizonStatus)->set([], 1),
            $processes,
            $min,
            $max,
            $info,
            $pools,
            MetricFamily::of(Metric::WaitThreshold)
                ->set(['connection' => 'redis', 'queue' => 'default'], 10)
                ->set(['connection' => 'redis', 'queue' => 'slow'], 30)
                ->set(['connection' => 'redis', 'queue' => 'reports'], 60),
            MetricFamily::of(Metric::HorizonInfo)
                ->set(['url' => 'http://localhost:8000/horizon', 'api_path' => '/horizon/observability', 'tts_url' => 'browser'], 1),
        ];
    }

    /**
     * @return list<MetricFamily>
     */
    private static function queues(): array
    {
        $default = ['connection' => 'redis', 'queue' => 'default'];
        $slow = ['connection' => 'redis', 'queue' => 'slow'];

        return [
            MetricFamily::of(Metric::QueuePendingJobs)->set($default, 7)->set($slow, 0),
            MetricFamily::of(Metric::QueueDelayedJobs)->set($default, 2)->set($slow, 0),
            MetricFamily::of(Metric::QueueDelayedDueJobs)->set($default, 1)->set($slow, 0),
            MetricFamily::of(Metric::QueueOldestPendingJobAge)->set($default, 17.25)->set($slow, 0),
            MetricFamily::of(Metric::QueueJobsPending)
                ->set([...$default, 'job_class' => 'App\\Jobs\\SendInvoice'], 4)
                ->set([...$default, 'job_class' => 'Closure (routes/web.php)'], 1)
                ->set([...$default, 'job_class' => Labels::UNKNOWN], 1)
                ->set([...$default, 'job_class' => Labels::UNSCANNED], 1),
            MetricFamily::of(Metric::QueueJobsReserved)->set([...$default, 'job_class' => 'App\\Jobs\\SendInvoice'], 2),
        ];
    }

    /**
     * @return list<MetricFamily>
     */
    private static function attempts(): array
    {
        $series = ['connection' => 'redis', 'queue' => 'default', 'job_class' => 'App\\Jobs\\SendInvoice'];
        $totals = MetricFamily::of(Metric::JobsTotal);

        foreach (['completed' => 40, 'released' => 0, 'retried' => 1, 'failed' => 1, 'timed_out' => 0] as $status => $count) {
            $totals->set([...$series, 'status' => $status], $count);
        }

        $duration = [];
        $wait = [];
        $cumulativeDuration = 0;
        $cumulativeWait = 0;
        $durationCounts = ['0.05' => 3, '0.1' => 10, '0.25' => 12, '0.5' => 8, '1' => 5, '2.5' => 3, '5' => 1];
        $waitCounts = ['0.05' => 20, '0.1' => 9, '0.25' => 6, '1' => 4, '10' => 2, '60' => 1];

        foreach (Config::DEFAULT_BUCKETS as $bound) {
            $key = (string) $bound;
            $cumulativeDuration += $durationCounts[$key] ?? 0;
            $cumulativeWait += $waitCounts[$key] ?? 0;
            $duration[$key] = $cumulativeDuration;
            $wait[$key] = $cumulativeWait;
        }

        $duration['+Inf'] = $cumulativeDuration;
        $wait['+Inf'] = $cumulativeWait + 1;

        return [
            $totals,
            MetricFamily::of(Metric::JobDuration)->observe($series, $duration, 17.375),
            MetricFamily::of(Metric::JobWait)->observe(['connection' => 'redis', 'queue' => 'default'], $wait, 312.5),
            MetricFamily::of(Metric::JobDurationMin)->set($series, 0.042),
            MetricFamily::of(Metric::JobDurationMax)->set($series, 4.75),
            MetricFamily::of(Metric::AttemptsDropped)->set([], 0),
            MetricFamily::of(Metric::LivePushTimestamp)->set([], 1792201716.123),
            MetricFamily::of(Metric::LivePushFailures)->set([], 2),
        ];
    }

    /**
     * @return list<MetricFamily>
     */
    private static function jobClasses(): array
    {
        return [
            MetricFamily::of(Metric::JobClasses)
                ->set(['job_class' => 'App\\Jobs\\SendInvoice'], 1)
                ->set(['job_class' => 'App\\Mail\\WelcomeMail'], 1)
                ->set(['job_class' => 'App\\Notifications\\InvoicePaid'], 1),
        ];
    }

    /**
     * @return list<MetricFamily>
     */
    private static function batches(): array
    {
        $export = ['batch' => 'export'];
        $recent = ['batch' => 'export', 'name' => 'export-2026-10-17'];
        $closed = ['batch' => 'import', 'name' => 'import-users'];

        return [
            MetricFamily::of(Metric::BatchesRunning)->set($export, 1),
            MetricFamily::of(Metric::BatchJobs)
                ->set([...$export, 'state' => 'total'], 100)
                ->set([...$export, 'state' => 'pending'], 40)
                ->set([...$export, 'state' => 'failed'], 2),
            MetricFamily::of(Metric::BatchOldestAge)->set($export, 125),
            MetricFamily::of(Metric::BatchJobCounts)
                ->set([...$recent, 'state' => 'total'], 100)
                ->set([...$recent, 'state' => 'pending'], 40)
                ->set([...$recent, 'state' => 'failed'], 2)
                ->set([...$closed, 'state' => 'total'], 10)
                ->set([...$closed, 'state' => 'pending'], 0)
                ->set([...$closed, 'state' => 'failed'], 0),
            MetricFamily::of(Metric::BatchCreated)->set($recent, 1792201591)->set($closed, 1792200000),
            MetricFamily::of(Metric::BatchClosed)->set($closed, 1792200420),
            MetricFamily::of(Metric::BatchJobClasses)
                ->set([...$export, 'queue' => 'default', 'job_class' => 'App\\Jobs\\ExportChunk'], 1)
                ->set(['batch' => 'import', 'queue' => 'slow', 'job_class' => 'App\\Jobs\\ImportUser'], 1),
            MetricFamily::of(Metric::BatchInfo)
                ->set([...$export, 'queue' => 'default', 'connection' => 'redis'], 1)
                ->set(['batch' => 'import', 'queue' => 'slow', 'connection' => 'redis'], 1),
            MetricFamily::of(Metric::BatchProgress)
                ->set([...$recent, 'status' => 'Running', 'id' => '9f3c1e2a-5b7d-4c1e-8f00-123456789abc'], 58)
                ->set([...$closed, 'status' => 'Finished', 'id' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d'], 100),
        ];
    }
}
