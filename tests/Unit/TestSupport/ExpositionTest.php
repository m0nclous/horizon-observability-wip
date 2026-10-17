<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\Support\Exposition;

it('parses samples, HELP and TYPE lines', function (): void {
    $exposition = Exposition::parse(implode("\n", [
        '# HELP ho_queue_jobs_total Finished job attempts by outcome.',
        '# TYPE ho_queue_jobs_total counter',
        'ho_queue_jobs_total{connection="redis",queue="default",job_class="App\\\\Jobs\\\\A",status="completed"} 42',
        '# HELP ho_queue_job_wait_seconds Wait with a back\\\\slash and\\nnewline.',
        '# TYPE ho_queue_job_wait_seconds histogram',
        'ho_queue_job_wait_seconds_bucket{connection="redis",queue="default",le="0.5"} 1',
        'ho_queue_job_wait_seconds_bucket{connection="redis",queue="default",le="+Inf"} 3',
        'ho_queue_job_wait_seconds_sum{connection="redis",queue="default"} 2.5',
        'ho_queue_job_wait_seconds_count{connection="redis",queue="default"} 3',
        'ho_horizon_info{url="https://x/h",api_path="/horizon/observability",tts_url="say \\"hi\\"\\n"} 1',
        'ho_gauge NaN',
        'ho_other +Inf 1700000000000',
        '',
    ]));

    expect($exposition->families())->toBe(['ho_queue_jobs_total', 'ho_queue_job_wait_seconds'])
        ->and($exposition->types['ho_queue_job_wait_seconds'])->toBe('histogram')
        ->and($exposition->help['ho_queue_job_wait_seconds'])->toBe("Wait with a back\\slash and\nnewline.")
        ->and($exposition->value('ho_queue_jobs_total', ['status' => 'completed', 'queue' => 'default', 'job_class' => 'App\\Jobs\\A', 'connection' => 'redis']))->toBe(42.0)
        ->and($exposition->value('ho_queue_job_wait_seconds_bucket', ['connection' => 'redis', 'queue' => 'default', 'le' => '+Inf']))->toBe(3.0)
        ->and($exposition->series('ho_queue_job_wait_seconds_sum'))->toBe(['connection="redis",queue="default"' => 2.5])
        ->and($exposition->value('ho_horizon_info', ['url' => 'https://x/h', 'api_path' => '/horizon/observability', 'tts_url' => "say \"hi\"\n"]))->toBe(1.0)
        ->and(is_nan((float) $exposition->value('ho_gauge')))->toBeTrue()
        ->and($exposition->value('ho_other'))->toBe(INF)
        ->and($exposition->value('ho_missing'))->toBeNull()
        ->and($exposition->series('ho_missing'))->toBe([]);
});

it('rejects malformed expositions', function (string $text): void {
    Exposition::parse($text);
})->with([
    'no value' => ['ho_x'],
    'bad value' => ['ho_x abc'],
    'unterminated label' => ['ho_x{a="b} 1'],
    'bad escape' => ['ho_x{a="\\t"} 1'],
    'duplicate sample' => ["ho_x{a=\"1\"} 1\nho_x{a=\"1\"} 2"],
    'duplicate label' => ['ho_x{a="1",a="2"} 1'],
])->throws(InvalidArgumentException::class);
