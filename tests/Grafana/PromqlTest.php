<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Promql;

/*
 * The PromQL helpers print exactly what the legacy generator wrote; filtered() is the legacy `filtered`.
 */

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
        ->and($promql->filtered('office_queue_jobs_reserved{queue=~".+"}', true))->toBe('office_queue_jobs_reserved{job_class=~"$job_class", queue=~".+"}')
        ->and($promql->filtered('office_horizon_status', true))->toBe('office_horizon_status');
});

it('adds the job filter to job families only when asked', function (): void {
    $promql = new Promql('office_');
    $expr = 'office_queue_jobs_total{status=~"failed"} / office_queue_delayed_jobs';

    expect($promql->filtered($expr, true))->toBe('office_queue_jobs_total{queue=~"$queue", job_class=~"$job_class", status=~"failed"} / office_queue_delayed_jobs{queue=~"$queue"}')
        ->and($promql->filtered($expr, false))->toBe('office_queue_jobs_total{queue=~"$queue", status=~"failed"} / office_queue_delayed_jobs{queue=~"$queue"}');
});

it('filters the families of its own prefix only', function (): void {
    expect((new Promql('acme_'))->filtered('acme_queue_pending_jobs + office_queue_pending_jobs', false))
        ->toBe('acme_queue_pending_jobs{queue=~"$queue"} + office_queue_pending_jobs');
});
