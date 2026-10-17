<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Attempts\JobClassLabel;
use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Labels;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\LogRecorder;

/*
| Cardinality guards of the attempts (Arch Q4 guard 1, C9). The pilot labelled attempts by any displayName and kept
| every field forever: about 25 series per distinct name, exported on every scrape. Now: a class set capped at
| max_job_classes inside the record EVAL, overflow as (other), a 30-day idle prune and lazy HDEL of gone classes.
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->keys = new Keys;
    $this->now = CarbonImmutable::createFromTimestamp(1_700_000_000.0);
    config()->set('horizon-observability.max_job_classes', 2);
    $this->app->forgetInstance(Config::class);
    $this->app->instance('log', $this->log = new LogRecorder);
    $this->classes = static function (): array {
        $members = Storage::store()->reader()->zrange((new Keys)->jobClasses(), 0, -1);
        sort($members);

        return $members;
    };
    $this->exported = static function (?CarbonImmutable $now = null): array {
        $classes = [];

        foreach (array_keys(Storage::scrape($now)['samples']['queue_jobs_total'] ?? []) as $labels) {
            preg_match('/job_class=(.*),status=/', $labels, $match);
            $classes[$match[1]] = true;
        }

        $classes = array_keys($classes);
        sort($classes);

        return $classes;
    };
});

it('records classes beyond max_job_classes as (other) and warns once (C9)', function (): void {
    Storage::record(jobClass: 'App\\Jobs\\A', now: $this->now);
    Storage::record(jobClass: 'App\\Jobs\\B', now: $this->now);
    Storage::record(jobClass: 'App\\Jobs\\C', now: $this->now);
    Storage::record(jobClass: 'App\\Jobs\\D', now: $this->now);
    Storage::record(jobClass: 'App\\Jobs\\A', now: $this->now);

    expect(($this->classes)())->toBe(['App\\Jobs\\A', 'App\\Jobs\\B'])
        ->and(Storage::hash($this->keys->attempts()))->toBe([
            'redis|default|(other)|completed' => '2',
            'redis|default|App\\Jobs\\A|completed' => '2',
            'redis|default|App\\Jobs\\B|completed' => '1',
        ])
        ->and(Storage::hash($this->keys->extremes(113_333_333, 'max')))->toHaveKey('redis|default|(other)')
        ->and(($this->exported)($this->now))->toBe(['(other)', 'App\\Jobs\\A', 'App\\Jobs\\B'])
        ->and($this->log->messages('warning'))->toBe(['horizon-observability: more than 2 job classes (max_job_classes); new classes are recorded as (other).']);
});

it('prunes classes idle for 30 days when the set is full and admits the new one', function (): void {
    $idle = 1_700_000_000 - AttemptStore::CLASS_IDLE_SECONDS - 1;
    Storage::store()->reader()->zadd($this->keys->jobClasses(), $idle, 'App\\Jobs\\Idle');
    Storage::record(jobClass: 'App\\Jobs\\Active', now: $this->now);

    Storage::record(jobClass: 'App\\Jobs\\New', now: $this->now);

    expect(($this->classes)())->toBe(['App\\Jobs\\Active', 'App\\Jobs\\New'])
        ->and(Storage::hash($this->keys->attempts()))->toHaveKeys(['redis|default|App\\Jobs\\New|completed'])
        ->and($this->log->messages('warning'))->toBe([]);
});

it('refreshes the last-seen score of a known class', function (): void {
    Storage::record(jobClass: 'App\\Jobs\\A', now: $this->now);
    Storage::record(jobClass: 'App\\Jobs\\A', now: $this->now->addDays(10));

    expect((float) Storage::store()->reader()->zscore($this->keys->jobClasses(), 'App\\Jobs\\A'))->toBe(1_700_000_000.0 + 864_000);
});

it('stops exporting classes idle for 30 days and deletes their fields lazily', function (): void {
    Storage::record(jobClass: 'App\\Jobs\\Gone', duration: 0.5, now: $this->now);
    Storage::record(jobClass: 'App\\Jobs\\Kept', duration: 0.5, now: $this->now->addDays(29));
    $later = $this->now->addDays(31);

    expect(($this->exported)($later))->toBe(['App\\Jobs\\Kept'])
        ->and(($this->classes)())->toBe(['App\\Jobs\\Kept'])
        ->and(array_keys(Storage::hash($this->keys->attempts())))->toBe(['redis|default|App\\Jobs\\Kept|completed'])
        ->and(array_keys(Storage::hash($this->keys->durationBuckets())))->toBe(['redis|default|App\\Jobs\\Kept|0.5'])
        ->and(array_keys(Storage::hash($this->keys->durationSum())))->toBe(['redis|default|App\\Jobs\\Kept']);
});

it('stops exporting a class that left the set and keeps (other)', function (): void {
    Storage::record(jobClass: 'App\\Jobs\\A', now: $this->now);
    Storage::record(jobClass: 'App\\Jobs\\B', now: $this->now);
    Storage::record(jobClass: 'App\\Jobs\\C', now: $this->now);
    Storage::store()->reader()->zrem($this->keys->jobClasses(), 'App\\Jobs\\B');

    expect(($this->exported)($this->now))->toBe(['(other)', 'App\\Jobs\\A'])
        ->and(array_keys(Storage::hash($this->keys->attempts())))->toBe(['redis|default|(other)|completed', 'redis|default|App\\Jobs\\A|completed']);
});

it('deletes at most 1000 stale fields per scrape', function (): void {
    $fields = [];

    foreach (range(1, 1500) as $index) {
        $fields[Keys::field('redis', 'default', 'App\\Jobs\\Gone'.$index, 'completed')] = '1';
    }

    Storage::store()->reader()->hmset($this->keys->attempts(), $fields);

    Storage::scrape($this->now);
    $afterFirst = count(Storage::hash($this->keys->attempts()));
    Storage::scrape($this->now);

    expect($afterFirst)->toBe(1500 - AttemptsCollector::FORGET_LIMIT)
        ->and(Storage::exists($this->keys->attempts()))->toBeFalse();
});

it('labels a missing displayName as (unknown) and strips closure line numbers through the tracker label', function (): void {
    config()->set('horizon-observability.max_job_classes', 500);
    $this->app->forgetInstance(Config::class);

    Storage::record(jobClass: JobClassLabel::normalize(null), now: $this->now);
    Storage::record(jobClass: JobClassLabel::normalize('Closure (routes/web.php:42)'), now: $this->now);
    Storage::record(jobClass: JobClassLabel::normalize('Closure (routes/web.php:57)'), now: $this->now);

    expect(($this->classes)())->toBe([Labels::UNKNOWN, 'Closure (routes/web.php)']);
});
