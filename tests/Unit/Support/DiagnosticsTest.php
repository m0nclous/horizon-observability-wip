<?php

declare(strict_types=1);

use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\CheckStatus;
use DF\HorizonObservability\Support\Diagnostics;

/*
| Pure helpers of Support\Diagnostics: the Redis Cluster hash tag of a prefixed key, the push-test stream id and the
| overall status.
*/

it('finds the hash tag Redis Cluster hashes', function (string $key, string $tag): void {
    expect(Diagnostics::hashTag($key))->toBe($tag);
})->with([
    ['laravel_horizon:{horizon-observability}:v1:meta', 'horizon-observability'],
    ['{app}:{horizon-observability}:v1:meta', 'app'],
    ['a{b:{horizon-observability}:v1:meta', 'b:{horizon-observability'],
    ['x{}:{horizon-observability}:v1:meta', ''],
    ['no-tag', ''],
    ['open{only', ''],
]);

it('cuts the push-test stream id to 64 characters', function (): void {
    expect(Diagnostics::pushTestStreamId('horizon_observability'))->toBe('horizon_observability_check')
        ->and(Diagnostics::pushTestStreamId(str_repeat('a', 64)))->toBe(str_repeat('a', 58).'_check')
        ->and(strlen(Diagnostics::pushTestStreamId(str_repeat('a', 64))))->toBe(64);
});

it('takes the worst status', function (): void {
    expect(Diagnostics::worst([]))->toBe(CheckStatus::Ok)
        ->and(Diagnostics::worst([CheckResult::info('a', 'b', 'c'), CheckResult::ok('a', 'b', 'c')]))->toBe(CheckStatus::Ok)
        ->and(Diagnostics::worst([CheckResult::warning('a', 'b', 'c', 'd'), CheckResult::ok('a', 'b', 'c')]))->toBe(CheckStatus::Warning)
        ->and(Diagnostics::worst([CheckResult::warning('a', 'b', 'c', 'd'), CheckResult::error('a', 'b', 'c', 'd')]))->toBe(CheckStatus::Error);
});

it('serialises a result without secrets', function (): void {
    expect(CheckResult::error('Live', 'Stream token', 'missing', 'Set it.')->toArray())->toBe([
        'section' => 'Live', 'name' => 'Stream token', 'status' => 'error', 'message' => 'missing', 'fix' => 'Set it.',
    ]);
});
