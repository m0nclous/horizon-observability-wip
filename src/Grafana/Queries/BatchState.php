<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Queries;

use DF\HorizonObservability\Grafana\Promql;

/**
 * Batches as Prometheus history: the collector exports a batch while it runs and for a day after it closed, so
 * the panels count individual batches of the period. A batch is the pair (batch, name): `batch` is its kind
 * (the name without numbers and uuids), and its progress series changes labels with its status.
 *
 * @internal
 */
final readonly class BatchState
{
    /**
     * The batch tables list the latest batches of a kind.
     */
    public const LATEST_PER_KIND = 20;

    public function __construct(private Promql $promql) {}

    /**
     * Current jobs of the running batches per kind in one state (legacy `BATCH_JOBS`).
     */
    public function jobs(string $state): string
    {
        return Promql::sum($this->promql->selector('queue_batch_jobs', Promql::eq('state', $state)), 'batch');
    }

    /**
     * Batches of the period: closed within it, or not closed by its end (legacy `BATCHES_IN_RANGE`).
     */
    public function inRange(): string
    {
        $closed = $this->last($this->promql->selector('queue_batch_closed_timestamp_seconds'));
        $created = $this->last($this->promql->selector('queue_batch_created_timestamp_seconds'));

        return Promql::paren(Promql::either(
            Promql::group($closed.' >= '.Promql::FROM_SECONDS, 'batch', 'name'),
            Promql::paren(Promql::unlessOn(Promql::group($created, 'batch', 'name'), $closed, 'batch', 'name')),
        ));
    }

    /**
     * Batch kinds with batches in the period (legacy `BATCH_KINDS`).
     */
    public function kinds(): string
    {
        return Promql::group($this->inRange(), 'batch');
    }

    /**
     * Kinds of the period that the Batch filter selects (legacy `BATCH_KINDS_SELECTED`).
     */
    public function selectedKinds(): string
    {
        $selected = $this->promql->selector('queue_batch_created_timestamp_seconds', Promql::re('batch', '$batch_kind'));

        return Promql::andOn($this->kinds(), Promql::group($this->last($selected), 'batch'), 'batch');
    }

    /**
     * Which queue and class each batch kind runs (legacy `BATCH_CLASSES`).
     */
    public function classes(): string
    {
        return Promql::group($this->promql->selector('queue_batch_job_classes'), 'batch', 'queue', 'job_class');
    }

    /**
     * A per-class value (labels queue, job_class) summed per batch kind its classes belong to
     * (legacy `per_batch_kind`).
     */
    public function perKind(string $perClass): string
    {
        return Promql::sum(
            Promql::binary($this->classes(), '*', Promql::sum($perClass, 'queue', 'job_class'), on: ['queue', 'job_class'], groupLeft: []),
            'batch',
        );
    }

    /**
     * $expr for the selected kinds, 0 for selected kinds without a value (legacy `kinds_or_zero`).
     */
    public function selectedKindsOrZero(string $expr): string
    {
        return Promql::either(
            Promql::andOn(Promql::paren($expr), $this->selectedKinds(), 'batch'),
            '0 * '.$this->selectedKinds(),
        );
    }

    /**
     * The latest batches of the kind the repeated table shows (legacy `BATCH_SELECTED`).
     */
    public function latestOfKind(): string
    {
        $created = $this->ofKind('queue_batch_created_timestamp_seconds');

        return Promql::topk(self::LATEST_PER_KIND, Promql::andOn($this->last($created), $this->inRange(), 'batch', 'name'), 'batch');
    }

    /**
     * The last value of a batch family in the period, for the latest batches of the kind (legacy `batch_value`).
     */
    public function valueOfLatest(string $family, string ...$matchers): string
    {
        return Promql::max(
            Promql::andOn($this->last($this->ofKind($family, ...$matchers)), $this->latestOfKind(), 'batch', 'name'),
            'batch',
            'name',
        );
    }

    /**
     * 1000 + progress percent of the latest batches, labelled with their status at the end of the period: a
     * closed batch by its last progress series, a running one only if it still runs at the end
     * (legacy `BATCH_STATUS_PROGRESS`).
     */
    public function statusProgress(int $offset): string
    {
        $progress = Promql::orOn(
            $this->last($this->ofKind('queue_batch_progress', Promql::ne('status', 'Running'))),
            $this->ofKind('queue_batch_progress', Promql::eq('status', 'Running')),
            'batch',
            'name',
        );

        return $offset.' + '.Promql::max(
            Promql::andOn(Promql::paren($progress), $this->latestOfKind(), 'batch', 'name'),
            'batch',
            'name',
            'status',
            'id',
        );
    }

    /**
     * How long each latest batch ran, or has run by the end of the period. Laravel never sets finished_at for
     * a batch with failures, so those get no duration (a dash, not 0) (legacy `BATCH_DURATION`).
     */
    public function durationOfLatest(): string
    {
        $created = $this->last($this->ofKind('queue_batch_created_timestamp_seconds'));
        $closed = $this->last($this->ofKind('queue_batch_closed_timestamp_seconds'));
        $end = Promql::orOn(Promql::paren($closed), Promql::paren('0 * '.$created.' + '.Promql::TO_SECONDS), 'batch', 'name');
        $duration = Promql::binary(Promql::paren($end), '-', $created, on: ['batch', 'name']);

        return Promql::unlessOn(
            Promql::max(Promql::andOn(Promql::paren($duration), $this->latestOfKind(), 'batch', 'name'), 'batch', 'name'),
            $this->last($this->ofKind('queue_batch_progress', Promql::eq('status', 'Failures'))),
            'batch',
            'name',
        );
    }

    /**
     * Last value in the period (legacy `BATCH_LAST`).
     */
    private function last(string $vector): string
    {
        return Promql::range('last_over_time', $vector, Promql::RANGE);
    }

    /**
     * A selector restricted to the kind of the repeated batch table (legacy `BATCH_KIND`).
     */
    private function ofKind(string $family, string ...$matchers): string
    {
        return $this->promql->selector($family, Promql::re('batch', '$batch_title'), ...$matchers);
    }
}
