<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Collectors\BatchesCollector;
use DF\HorizonObservability\Metrics\Labels;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\Batches\Support\BatchTable;
use DF\HorizonObservability\Tests\Integration\Batches\Support\Families;

/*
| Batch label cardinality: the pilot's batch labels grew without bound: hex tokens survived the kind normaliser as
| letter fragments, every kind was exported and every batch carried size and created labels. Now kinds fold into (other)
| after 100, per-batch series are capped at 200 per kind and 2000 per scrape, and hex tokens are stripped.
*/

pest()->group('redis');

const CAPS_TEST_NOW = 1_760_000_000;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestamp(CAPS_TEST_NOW));
    BatchTable::create();
});

/**
 * @return array<string, array<string, int|float>>
 */
function capsTestCollect(): array
{
    $context = new ScrapeContext(CarbonImmutable::createFromTimestamp(CAPS_TEST_NOW), 30.0);
    $families = Families::of(app(BatchesCollector::class)->collect($context));

    expect($context->takeFailures())->toBe([]);

    return $families;
}

/**
 * The distinct values of one label in a family.
 *
 * @param  array<string, int|float>  $series
 * @return list<string>
 */
function capsTestLabelValues(array $series, string $label): array
{
    $values = [];

    foreach (array_keys($series) as $key) {
        if (preg_match('/(?:^|,)'.$label.'="([^"]*)"/', $key, $match) === 1) {
            $values[$match[1]] = true;
        }
    }

    $values = array_map(strval(...), array_keys($values));
    sort($values, SORT_STRING);

    return $values;
}

it('strips hex tokens from batch kinds, so one kind stays one series', function (): void {
    foreach (['export-ab3f9e', 'export-1c2d3e4f', 'export-9e8d7c6b5a', 'Export 2026-10-17 0f9e8d'] as $i => $name) {
        BatchTable::insert(['id' => 'b'.$i, 'name' => $name, 'created' => CAPS_TEST_NOW - 10 * $i]);
    }

    $families = capsTestCollect();

    // The pilot exported export-abfe, export-cdef, export-edcba and Export_fed.
    expect(capsTestLabelValues($families['queue_batches_running'], 'batch'))->toBe(['Export', 'export'])
        ->and($families['queue_batches_running']['batch="export"'])->toBe(3);
});

it('folds kinds beyond the 100 most recently active into (other)', function (): void {
    $now = CAPS_TEST_NOW;
    $rows = [];

    // 105 kinds; kind-k was last active k seconds ago, so kind-100 ... kind-104 are the least recently active.
    foreach (range(0, 104) as $k) {
        $rows[] = ['id' => 'run-'.$k, 'name' => 'kind-'.chr(97 + intdiv($k, 26)).chr(97 + $k % 26), 'total' => 10, 'pending' => 4, 'failed' => 1, 'created' => $now - 1000 - $k];
    }

    BatchTable::insertMany($rows);

    // A kind seen only in the links is ranked by the link time: it is the most recently active one.
    app(RedisClients::class)->storage()->zadd((new Keys)->batchLinks(), $now, Keys::field('linked-only', 'redis', 'mail', 'App\\Jobs\\Mail'));
    // A link of a folded kind is folded too.
    app(RedisClients::class)->storage()->zadd((new Keys)->batchLinks(), $now - 86400, Keys::field('kind-ea', 'redis', 'slow', 'App\\Jobs\\Old'));

    $families = capsTestCollect();
    $kinds = capsTestLabelValues($families['queue_batches_running'], 'batch');

    // Kept: linked-only and kind-aa ... kind-du (k = 0 ... 98). Folded: kind-dv ... kind-ea (k = 99 ... 104); kind-ea's
    // link is a day old, older than its batch.
    expect($kinds)->toHaveCount(BatchesCollector::MAX_KINDS)
        ->and($kinds)->toContain(Labels::OTHER, 'kind-aa', 'kind-du')
        ->and($kinds)->not->toContain('kind-dv', 'kind-dz', 'kind-ea', 'linked-only')
        ->and($families['queue_batches_running']['batch="(other)"'])->toBe(6)
        ->and($families['queue_batch_jobs']['batch="(other)",state="total"'])->toBe(60)
        ->and($families['queue_batch_jobs']['batch="(other)",state="pending"'])->toBe(24)
        ->and($families['queue_batch_oldest_age_seconds']['batch="(other)"'])->toBe(1000 + 104)
        // Per-batch families only for the kinds that are not folded.
        ->and(capsTestLabelValues($families['queue_batch_progress'], 'batch'))->toHaveCount(99)
        ->and(capsTestLabelValues($families['queue_batch_progress'], 'batch'))->not->toContain(Labels::OTHER)
        ->and(array_keys($families['queue_batch_info']))->toBe([
            'batch="(other)",queue="slow",connection="redis"',
            'batch="linked-only",queue="mail",connection="redis"',
        ])
        ->and(array_keys($families['queue_batch_job_classes']))->toBe([
            'batch="(other)",queue="slow",job_class="App\\Jobs\\Old"',
            'batch="linked-only",queue="mail",job_class="App\\Jobs\\Mail"',
        ]);
});

it('exports at most 200 batches per kind, the newest', function (): void {
    $now = CAPS_TEST_NOW;
    $rows = [];

    foreach (range(1, 250) as $i) {
        $rows[] = ['id' => sprintf('b-%03d', $i), 'name' => sprintf('import #%03d', $i), 'created' => $now - $i];
    }

    BatchTable::insertMany($rows);

    $families = capsTestCollect();
    $ids = capsTestLabelValues($families['queue_batch_progress'], 'id');

    expect($ids)->toHaveCount(BatchesCollector::MAX_BATCHES_PER_KIND)
        ->and($ids[0])->toBe('b-001')
        ->and($ids[199])->toBe('b-200')
        ->and($families['queue_batch_created_timestamp_seconds'])->toHaveCount(200)
        ->and($families['queue_batch_job_counts'])->toHaveCount(600)
        // The per-kind families still count every running batch.
        ->and($families['queue_batches_running'])->toBe(['batch="import #"' => 250]);
});

it('exports at most 2000 batches per scrape, the newest', function (): void {
    $now = CAPS_TEST_NOW;
    $rows = [];

    // 15 kinds of 150 batches each: 2250 batches, all within the per-kind cap.
    foreach (range(0, 14) as $k) {
        foreach (range(0, 149) as $i) {
            $rows[] = ['id' => sprintf('k%02d-%03d', $k, $i), 'name' => 'kind '.chr(97 + $k).' #'.$i, 'created' => $now - ($i * 15 + $k)];
        }
    }

    BatchTable::insertMany($rows);

    $families = capsTestCollect();
    $created = $families['queue_batch_created_timestamp_seconds'];

    expect($families['queue_batch_progress'])->toHaveCount(BatchesCollector::MAX_BATCHES)
        ->and($created)->toHaveCount(BatchesCollector::MAX_BATCHES)
        ->and(min($created))->toBe($now - 1999)
        ->and($families['queue_batches_running'])->toHaveCount(15);
});

it('drops the size and created labels of the pilot', function (): void {
    BatchTable::insert(['id' => 'b', 'name' => 'import']);

    expect(array_keys(capsTestCollect()['queue_batch_progress']))->toBe(['batch="import",name="import",status="Running",id="b"']);
});
