<?php

declare(strict_types=1);

use DF\HorizonObservability\Collectors\Collector;
use DF\HorizonObservability\Collectors\HorizonCollector;
use DF\HorizonObservability\Collectors\QueueCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Demo\DemoJob;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\CompletingJob;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Support\RunsWorker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

/*
| The collector dry run of horizon-observability:check (a number check prints must equal the scrape): per collector,
| the families and samples it reports are those GET {base}/metrics renders (TextFormat leaves out the empty
| families), with Horizon running and without, with and without recorded attempts and batches. The dry run fails a
| collector the scrape would drop, and warns when it takes longer than the budget of a 5 s scrape.
*/

pest()->group('redis');
uses(RunsWorker::class);

const CHECK_DRY_RUN_TOKEN = 'check-dry-run-token-0123456789';

beforeEach(function (): void {
    config()->set('horizon-observability.token', CHECK_DRY_RUN_TOKEN);
    config()->set('queue.failed.driver', 'null');
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance('queue.failer');
});

/**
 * The families and samples per collector of a real scrape: collector id => [families, samples], for every collector
 * the scrape ran (its exporter_collector_success sample), also when it rendered nothing.
 *
 * @return array<string, array{0: int, 1: int}>
 */
function checkDryRunScrape(string $text, string $prefix): array
{
    $counts = [];
    $collector = null;
    preg_match_all('/^'.preg_quote($prefix, '/').'exporter_collector_success\{collector="([a-z_]+)"\} 1$/m', $text, $ran);

    foreach ($ran[1] as $id) {
        $counts[$id] = [0, 0];
    }

    foreach (explode("\n", $text) as $line) {
        if (str_starts_with($line, '# TYPE ')) {
            $name = substr(explode(' ', $line)[2], strlen($prefix));
            $collector = Metric::from($name)->collector();
            $counts[$collector] ??= [0, 0];
            $counts[$collector][0]++;
        } elseif ($line !== '' && ! str_starts_with($line, '#') && $collector !== null) {
            $counts[$collector][1]++;
        }
    }

    unset($counts[Metric::SELF]);

    return $counts;
}

/**
 * The families and samples per collector the dry run reports: collector id => [families, samples].
 *
 * @return array<string, array{0: int, 1: int}>
 */
function checkDryRunReport(): array
{
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);
    $counts = [];

    foreach (app(Diagnostics::class)->check() as $result) {
        if ($result->section !== 'Collectors' || $result->status->value !== 'ok') {
            continue;
        }

        expect($result->message)->toMatch('/^(\d+) famil(?:y|ies), (\d+) samples? in \d+\.\d{3} s$/');
        preg_match('/^(\d+) famil(?:y|ies), (\d+) samples?/', $result->message, $match);
        $counts[$result->name] = [(int) $match[1], (int) $match[2]];
    }

    return $counts;
}

function checkDryRunBatchTable(): void
{
    Schema::connection('testing')->create('job_batches', static function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
}

it('reports per collector the families and samples GET /metrics renders', function (bool $horizon, bool $data): void {
    if ($horizon) {
        HorizonState::using($this->app)
            ->master('host-a', 'running', ['host-a:supervisor-1'])
            ->supervisor('host-a:supervisor-1', HorizonState::options('host-a:supervisor-1'), ['redis:default' => 2]);
    }

    if ($data) {
        checkDryRunBatchTable();
        // One recorded attempt (counter, duration and wait histograms, extremes) and one job still pending.
        CompletingJob::dispatch(5)->onConnection('redis');
        $this->runWorker();
        CompletingJob::dispatch()->onConnection('redis');
        Bus::batch([new DemoJob('Import', 'completed', 0), new DemoJob('Import', 'completed', 0)])
            ->name('Nightly import')
            ->onConnection('redis')
            ->dispatch();
    }

    $response = $this->get('/horizon/observability/metrics', ['Authorization' => 'Bearer '.CHECK_DRY_RUN_TOKEN])->assertOk();
    $scrape = checkDryRunScrape((string) $response->getContent(), app(Config::class)->prefix());
    $report = checkDryRunReport();

    ksort($scrape);
    ksort($report);

    // Every collector the scrape ran, with the same numbers; the empty families of a collector count in neither.
    expect($report)->toBe($scrape)
        ->and(array_keys($report))->toBe($data ? ['attempts', 'batches', 'horizon', 'job_classes', 'queues'] : ['attempts', 'horizon', 'job_classes', 'queues']);

    if (! $data) {
        // Nothing recorded yet: the attempts collector renders one family (the dropped-attempts counter).
        expect($report['attempts'])->toBe([1, 1]);
    } else {
        expect($report['batches'][0])->toBeGreaterThan(0)
            ->and($report['attempts'][0])->toBeGreaterThan(1);
    }
})->with([
    'Horizon running, no attempts' => [true, false],
    'Horizon running, attempts and batches' => [true, true],
    'Horizon stopped, no attempts' => [false, false],
]);

it('fails a collector whose families the scrape would drop', function (): void {
    // A family of another collector: Exporter::scrape() drops all of the collector's families.
    $this->app->instance(HorizonCollector::class, new class implements Collector
    {
        public function name(): string
        {
            return 'horizon';
        }

        public function collect(ScrapeContext $context): iterable
        {
            yield MetricFamily::of(Metric::HorizonStatus)->set([], 1);
            yield MetricFamily::of(Metric::QueuePendingJobs)->set(['connection' => 'redis', 'queue' => 'default'], 1);
        }
    });

    $horizon = array_values(array_filter(app(Diagnostics::class)->check(), static fn (CheckResult $result): bool => $result->section === 'Collectors' && $result->name === 'horizon'))[0];

    expect($horizon->status->value)->toBe('error')
        ->and($horizon->message)->toContain('InvalidMetric: The horizon collector yielded queue_pending_jobs, a family of the queues collector.');
});

it('warns when the dry run takes longer than the budget of a 5 s scrape', function (float $step, bool $warns): void {
    // Each clock reading moves on by $step: every collector takes $step seconds (batches is off in auto mode).
    $now = 0.0;
    $this->app->instance(Diagnostics::class, new Diagnostics($this->app, static function () use (&$now, $step): float {
        return $now += $step;
    }));
    $this->app->instance(QueueCollector::class, new class implements Collector
    {
        public function name(): string
        {
            return 'queues';
        }

        public function collect(ScrapeContext $context): iterable
        {
            return [];
        }
    });

    $results = array_values(array_filter(app(Diagnostics::class)->check(), static fn (CheckResult $result): bool => $result->section === 'Collectors'));
    $byName = array_column(array_map(static fn (CheckResult $result): array => $result->toArray(), $results), null, 'name');

    expect($byName['queues']['message'])->toBe(sprintf('0 families, 0 samples in %s s', number_format($step, 3)));

    if (! $warns) {
        expect($byName)->not->toHaveKey('Duration');

        return;
    }

    expect($byName['Duration'])->toMatchArray([
        'status' => 'warning',
        'message' => sprintf('the dry run took %s s, more than the 4.5 s budget of a 5 s scrape', number_format(4 * $step, 3)),
        'fix' => 'Scrape every 10 s with scrape_timeout 8s, or lower queue_scan_limit and add the timeouts above.',
    ]);
})->with([
    '4 x 1.125 s = 4.5 s: within the budget' => [1.125, false],
    '4 x 1.5 s = 6 s: over it' => [1.5, true],
]);
