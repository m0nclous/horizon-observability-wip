<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Promql;

/*
 * The PromQL helpers print exactly what the legacy generator wrote; filtered() is the legacy `filtered`.
 */

pest()->group('grafana');

it('quotes string literals', function (): void {
    expect(Promql::quote("a\"b\\c\td\ne"))->toBe('"a\"b\\\\c\td\ne"');
});

it('prints aggregations, functions and vector matching', function (): void {
    expect(Promql::sum('x', 'queue', 'job_class'))->toBe('sum by (queue, job_class) (x)')
        ->and(Promql::min('x'))->toBe('min(x)')
        ->and(Promql::countValues('v', 'x', 'queue'))->toBe('count_values by (queue) ("v", x)')
        ->and(Promql::topk(20, 'x', 'batch'))->toBe('topk by (batch) (20, x)')
        ->and(Promql::range('last_over_time', 'x', '1d', offset: '$__range'))->toBe('last_over_time(x[1d] offset $__range)')
        ->and(Promql::range('last_over_time', '(x)', '1s:1s', at: Promql::TO_SECONDS))->toBe('last_over_time((x)[1s:1s] @ ${__to:date:seconds})')
        ->and(Promql::binary('a', '*', 'b', on: ['queue'], groupLeft: ['limit']))->toBe('a * on (queue) group_left (limit) b')
        ->and(Promql::binary('a', '*', 'b', on: [], groupLeft: []))->toBe('a * on () group_left () b')
        ->and(Promql::andOn('a', 'b'))->toBe('a and on () b')
        ->and(Promql::withLabel('vector(1)', 'queue', 'x'))->toBe('label_replace(vector(1), "queue", "x", "", "")')
        ->and(Promql::labelJoin('x', 'cell', ' / ', 'age', 'limit'))->toBe('label_join(x, "cell", " / ", "age", "limit")');
});

it('adds the queue filter to queue families without a queue matcher of their own', function (): void {
    $promql = new Promql('office_');

    expect($promql->filtered('sum(office_queue_pending_jobs)', false))->toBe('sum(office_queue_pending_jobs{queue=~"$queue"})')
        ->and($promql->filtered('office_queue_jobs_reserved{queue=~".+"}', false))->toBe('office_queue_jobs_reserved{queue=~".+"}')
        ->and($promql->filtered('office_horizon_status', true))->toBe('office_horizon_status');
});

it('adds the job filter and the batch scope to job families only when asked (ADDENDUM R2.4 O2)', function (): void {
    $promql = new Promql('office_');
    $expr = 'office_queue_jobs_total{status=~"failed"} / office_queue_delayed_jobs';
    $scope = ' unless on (job_class) '.$promql->batchExcluded().')';

    expect($promql->filtered($expr, true))->toBe('(office_queue_jobs_total{queue=~"$queue", job_class=~"$job_class", status=~"failed"}'.$scope.' / office_queue_delayed_jobs{queue=~"$queue"}')
        ->and($promql->filtered($expr, false))->toBe('office_queue_jobs_total{queue=~"$queue", status=~"failed"} / office_queue_delayed_jobs{queue=~"$queue"}')
        ->and($promql->batchExcluded())->toBe(
            '(group by (job_class) ({__name__=~"office_queue_jobs_total|office_queue_jobs_pending|office_queue_jobs_reserved", job=~"$prometheus_job", __batch_scope__!~"$batch_kind"})'
            .' unless on (job_class) group by (job_class) ({__name__=~"office_queue_batch_job_classes", job=~"$prometheus_job", batch=~"$batch_kind", __batch_scope__!~"$batch_kind"}))',
        )
        ->and($promql->batchExcluded('queue'))->toBe(
            '(group by (queue) ({__name__=~"office_queue_pending_jobs", job=~"$prometheus_job", __batch_scope__!~"$batch_kind"})'
            .' unless on (queue) group by (queue) ({__name__=~"office_queue_batch_job_classes", job=~"$prometheus_job", batch=~"$batch_kind", __batch_scope__!~"$batch_kind"}))',
        );
});

it('scopes a job-filtered selector outside its range function and replica dedupe', function (): void {
    $promql = new Promql('ho_');
    $scope = ' unless on (job_class) '.$promql->batchExcluded().')';
    $at = '${__to:date:seconds}';

    expect($promql->filtered('sum by (queue) (max by (job, connection, queue, job_class, status) (last_over_time(ho_queue_jobs_total{job=~"$prometheus_job", status=~"x"}[10m] offset -$__interval)))', true))
        ->toBe('sum by (queue) ((max by (job, connection, queue, job_class, status) (last_over_time(ho_queue_jobs_total{job=~"$prometheus_job", queue=~"$queue", job_class=~"$job_class", status=~"x"}[10m] offset -$__interval))'.$scope.')')
        // A vector at a fixed time takes the scope of that time, so the unit stays step-invariant
        ->and($promql->filtered('max by (job, connection, queue, job_class, status) (ho_queue_jobs_total @ '.$at.')', true))
        ->toBe('(max by (job, connection, queue, job_class, status) (ho_queue_jobs_total{queue=~"$queue", job_class=~"$job_class"} @ '.$at.') unless on (job_class) '.$promql->batchExcluded('job_class', $at).')')
        ->and($promql->filtered('max by (job, connection, queue, job_class, status) (last_over_time(ho_queue_jobs_total[10m] offset -$__interval @ end()))', true))
        ->toBe('(max by (job, connection, queue, job_class, status) (last_over_time(ho_queue_jobs_total{queue=~"$queue", job_class=~"$job_class"}[10m] offset -$__interval @ end())) unless on (job_class) '.$promql->batchExcluded('job_class', 'end()').')')
        ->and($promql->batchExcluded('job_class', 'start()'))->toBe(
            '(group by (job_class) ({__name__=~"ho_queue_jobs_total|ho_queue_jobs_pending|ho_queue_jobs_reserved", job=~"$prometheus_job", __batch_scope__!~"$batch_kind"} @ start())'
            .' unless on (job_class) group by (job_class) ({__name__=~"ho_queue_batch_job_classes", job=~"$prometheus_job", batch=~"$batch_kind", __batch_scope__!~"$batch_kind"} @ start()))',
        )
        // Without the dedupe (an option that only shows what it prevents) the scope follows the range function
        ->and($promql->filtered('sum(min_over_time(ho_queue_jobs_pending[$__range]))', true))
        ->toBe('sum((min_over_time(ho_queue_jobs_pending{queue=~"$queue", job_class=~"$job_class"}[$__range])'.$scope.')')
        // A selector with a job_class matcher of its own keeps it and gets no scope from filtered()
        ->and($promql->filtered('sum(ho_queue_jobs_reserved{job_class=~"$job_class"})', true))
        ->toBe('sum(ho_queue_jobs_reserved{queue=~"$queue", job_class=~"$job_class"})');
});

it('leaves family names inside strings alone', function (): void {
    $promql = new Promql('ho_');
    $expr = 'group by (job_class) ({__name__=~"ho_queue_jobs_total|ho_queue_jobs_pending"}) or label_replace(vector(1), "x", "ho_queue_pending_jobs{a=\\"}\\"}", "", "")';

    expect($promql->filtered($expr, true))->toBe($expr)
        ->and($promql->filtered('ho_queue_pending_jobs{queue_x="a}b"}', false))->toBe('ho_queue_pending_jobs{queue=~"$queue", queue_x="a}b"}');
});

it('filters the families of its own prefix only', function (): void {
    expect((new Promql('acme_'))->filtered('acme_queue_pending_jobs + office_queue_pending_jobs', false))
        ->toBe('acme_queue_pending_jobs{queue=~"$queue"} + office_queue_pending_jobs');
});
