<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Record;

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Category;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Manifest;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Recording;
use DF\HorizonObservability\Tests\DashboardTruth\Datasets\Dataset;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Events\JobPushed;
use LogicException;
use RuntimeException;

/**
 * The record stage of one dataset (design sections 4 and 6.2).
 *
 * `Scenario::start()`, then at once `advance(E_k - now)`, a negative offset: T16's scenario engine runs unchanged on a
 * clock at the dataset's epoch, and its `freeze()` pins Carbon, CarbonImmutable and the storage `ServerClock`, so the
 * exporter's 90 s new-series hold runs on the virtual clock too. Horizon and `Illuminate\Bus` read it through
 * horizon-clock-shim.php.
 *
 * The recorder then visits the union of the dataset's event times and every target's scrape slots in time order. At
 * an event it runs the event's jobs through real workers. At a slot it writes the Horizon records of the topology
 * ({@see HorizonWorld}), scrapes the real endpoint through T16's `Scenario::scrape()` (token, invariants, the
 * decrease check) and stores `{target, t, ok, exposition}`; a failed slot stores no exposition. At every scrape the
 * L1 check compares `queue_jobs_total` with T16's model (an `exporter mismatch` otherwise).
 *
 * Guards (a violation names the shim): every TruthJob payload's `pushedAt` is its virtual dispatch time, every batch
 * row's timestamps are virtual, every Horizon job-record stamp lies in [virtual, virtual + 5 s].
 *
 * At the end, {@see TtlFreeze} persists the world and the manifest is written.
 */
final class Recorder
{
    private const STAMP_SLACK = 5.0;

    private const SHIM = 'tests/DashboardTruth/Record/horizon-clock-shim.php';

    /**
     * @var list<array{id: string, at: float}> the TruthJobs pushed since the last stamp check
     */
    private array $pushed = [];

    /**
     * @var array<string, float> batch id => virtual dispatch time
     */
    private array $batches = [];

    public function __construct(
        private readonly RecordingCase $test,
        private readonly Dataset $dataset,
        private readonly string $directory,
    ) {}

    public function record(): Manifest
    {
        if (! HorizonClock::shimmed()) {
            throw new LogicException('The clock shims are not loaded: RecordTest requires '.self::SHIM.' at file load with DASHBOARD_TRUTH_RECORD=1.');
        }

        $this->createBatchTable();
        HorizonClock::reset();
        $scenario = Scenario::start($this->test);
        $epoch = $this->dataset->epoch();
        $scenario->advance($epoch - $scenario->unix());
        HorizonClock::moved();
        $this->listen($scenario);

        $world = new HorizonWorld($this->dataset->topology());
        $recording = Recording::create($this->directory.'/recording.ndjson');
        $events = [];
        $scrapes = 0;

        try {
            foreach ($this->timeline() as $step) {
                $scenario->advance($step['at'] - $scenario->unix());
                HorizonClock::moved();

                if ($step['event'] !== null) {
                    ($step['event']['run'])($scenario);
                    $this->checkStamps($scenario);
                    $events[] = ['at' => $step['at'], 'kind' => $step['event']['kind'], 'detail' => $step['event']['detail']];

                    continue;
                }

                /** @var array{name: string, failed: list<int>} $target */
                $target = $step['target'];
                $time = (int) round($step['at'] * 1000);

                if (in_array($time, $target['failed'], true)) {
                    $recording->append($target['name'], $time, false, null);

                    continue;
                }

                $world->heartbeat(Scenario::app(), $step['at']);
                $samples = new Samples($scenario->scrape());
                $this->checkL1($scenario, $samples);
                $recording->append($target['name'], $time, true, $this->test->lastScrape());
                $scrapes++;
            }
        } finally {
            $recording->close();
        }

        if ($scrapes === 0 || HorizonClock::reads()['frozen'] === 0) {
            throw new LogicException('The recorder made no scrape, or Horizon never read the shimmed clock ('.self::SHIM.').');
        }

        $this->checkBatches();
        $ttl = TtlFreeze::freeze($this->test->prefix());
        $manifest = new Manifest(
            dataset: $this->dataset->name(),
            epoch: $epoch,
            end: $epoch + $this->dataset->span(),
            scrapeInterval: $this->dataset->scrapeInterval(),
            prefix: $this->test->prefix(),
            sqlite: $this->test->sqlite(),
            token: Scenario::TOKEN,
            targets: $this->dataset->targets(),
            topology: $this->dataset->topology(),
            events: $events,
            attempts: array_map(static fn (array $attempt): array => [
                'connection' => $attempt['connection'],
                'queue' => $attempt['queue'],
                'class' => $attempt['class'],
                'status' => $attempt['status'],
                'at' => $attempt['at'],
            ], $scenario->model->attempts()),
            ttl: $ttl,
            renders: array_map(static fn (Mode $mode): string => $mode->value, $this->dataset->renders()),
        );
        $manifest->write($this->directory.'/manifest.json');

        return $manifest;
    }

    /**
     * The event times and scrape slots in time order: at an equal time the event comes first (datasets keep them
     * apart).
     *
     * @return list<array{at: float, event: array{at: float, kind: string, detail: string, run: callable(Scenario): void}|null, target: array<string, mixed>|null}>
     */
    private function timeline(): array
    {
        $epoch = $this->dataset->epoch();
        $steps = [];

        foreach ($this->dataset->events() as $event) {
            $steps[] = ['at' => $epoch + $event['at'], 'event' => $event, 'target' => null, 'order' => 0];
        }

        foreach ($this->dataset->targets() as $target) {
            for ($slot = 0; ; $slot++) {
                // Whole milliseconds, as Prometheus stores them
                $at = round($epoch + $target['phase'] + $slot * $target['interval'], 3);

                if ($at > $epoch + $this->dataset->span()) {
                    break;
                }

                if ($at >= $target['from'] && $at <= $target['to']) {
                    $steps[] = ['at' => $at, 'event' => null, 'target' => $target, 'order' => 1];
                }
            }
        }

        usort($steps, static fn (array $a, array $b): int => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);

        return array_map(static fn (array $step): array => ['at' => $step['at'], 'event' => $step['event'], 'target' => $step['target']], $steps);
    }

    /**
     * L1 at every scrape (design section 7.1): the attempt counters equal T16's model, the held new series restated.
     */
    private function checkL1(Scenario $scenario, Samples $samples): void
    {
        $expected = $scenario->model->jobsTotal($scenario->unix());
        $actual = $samples->integers(Metric::JobsTotal->value);

        if ($actual !== $expected) {
            $missing = array_diff_assoc($expected, $actual);
            $extra = array_diff_assoc($actual, $expected);

            throw new RuntimeException(sprintf(
                "%s at %s: queue_jobs_total differs from T16's model.\nexpected, not exported: %s\nexported, not expected: %s",
                Category::ExporterMismatch->value,
                $scenario->now()->format('Y-m-d\TH:i:s.vP'),
                json_encode($missing),
                json_encode($extra),
            ));
        }
    }

    /**
     * Record the TruthJobs Horizon pushes and assert their `pushedAt`, and the batches created.
     */
    private function listen(Scenario $scenario): void
    {
        Event::listen(JobPushed::class, function (JobPushed $event) use ($scenario): void {
            $payload = $event->payload->decoded;
            $command = is_array($payload['data'] ?? null) && is_string($payload['data']['command'] ?? null)
                ? unserialize($payload['data']['command'])
                : null;

            if (! $command instanceof TruthJob) {
                return;
            }

            $virtual = $scenario->unix();
            $pushedAt = is_numeric($payload['pushedAt'] ?? null) ? (float) $payload['pushedAt'] : null;

            if ($pushedAt === null || abs($pushedAt - $virtual) > 0.001) {
                throw new LogicException(sprintf('pushedAt %s of %s is not its virtual dispatch time %.3f: Laravel\Horizon\microtime() is not in effect (%s).', var_export($pushedAt, true), $command->label, $virtual, self::SHIM));
            }

            $this->pushed[] = ['id' => (string) $event->payload->id(), 'at' => $virtual];

            if (is_string($command->batchId) && $command->batchId !== '') {
                $this->batches[$command->batchId] ??= $virtual;
            }
        });
    }

    /**
     * Every stamp of the Horizon job records of the jobs pushed since the last check lies in [virtual, virtual + 5 s]
     * (the Repositories shim drifts by the real time since the clock moved).
     */
    private function checkStamps(Scenario $scenario): void
    {
        $redis = Scenario::app()->make('redis')->connection('horizon');
        $now = $scenario->unix();

        foreach ($this->pushed as ['id' => $id, 'at' => $at]) {
            $record = $redis->hgetall($id);

            if (! is_array($record) || $record === []) {
                throw new LogicException(sprintf('Horizon has no job record %s (pushed at %.3f): its EXPIREAT ran on the virtual clock.', $id, $at));
            }

            foreach (['reserved_at', 'completed_at', 'failed_at', 'updated_at'] as $field) {
                if (! isset($record[$field]) || ! is_numeric($record[$field])) {
                    continue;
                }

                $stamp = (float) $record[$field];

                if ($stamp < $at - 0.001 || $stamp > $now + self::STAMP_SLACK) {
                    throw new LogicException(sprintf('The Horizon job record %s has %s = %.3f outside [%.3f, %.3f]: Laravel\Horizon\Repositories\microtime() is not in effect (%s).', $id, $field, $stamp, $at, $now + self::STAMP_SLACK, self::SHIM));
                }
            }
        }

        $this->pushed = [];
    }

    /**
     * Every batch row's timestamps are virtual: `created_at` the whole second of its dispatch, `finished_at` and
     * `cancelled_at` within the dataset.
     */
    private function checkBatches(): void
    {
        $span = [$this->dataset->epoch(), $this->dataset->epoch() + $this->dataset->span()];

        foreach (DB::connection(RecordingCase::CONNECTION)->table('job_batches')->get() as $row) {
            $id = (string) $row->id;
            $created = (int) $row->created_at;

            if (isset($this->batches[$id]) && $created !== (int) floor($this->batches[$id])) {
                throw new LogicException(sprintf('Batch %s was created at %d, not at its virtual dispatch time %d: Illuminate\Bus\time() is not in effect (%s).', $id, $created, (int) floor($this->batches[$id]), self::SHIM));
            }

            foreach (['created_at', 'finished_at', 'cancelled_at'] as $column) {
                $value = $row->{$column};

                if ($value !== null && ((int) $value < $span[0] || (int) $value > $span[1])) {
                    throw new LogicException(sprintf('Batch %s has %s = %d outside the dataset [%d, %d] (%s).', $id, $column, (int) $value, $span[0], $span[1], self::SHIM));
                }
            }
        }
    }

    /**
     * Laravel's `job_batches` table in the world's database.
     */
    private function createBatchTable(): void
    {
        $schema = Schema::connection(RecordingCase::CONNECTION);

        if ($schema->hasTable('job_batches')) {
            return;
        }

        $schema->create('job_batches', static function (Blueprint $table): void {
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
}
