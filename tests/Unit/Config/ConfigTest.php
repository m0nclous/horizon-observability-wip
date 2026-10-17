<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Config\ConfigProblem;
use DF\HorizonObservability\Config\ExceptionMode;

/**
 * @param  array<string, mixed>  $values  keys below horizon-observability (dot notation)
 */
function observabilityConfig(array $values = []): Config
{
    foreach ($values as $key => $value) {
        config()->set('horizon-observability.'.$key, $value);
    }

    return Config::fromRepository(app('config'));
}

/**
 * @return list<string>
 */
function problemPaths(Config $config): array
{
    return array_map(static fn (ConfigProblem $problem): string => $problem->path, $config->problems);
}

it('reads the package defaults without problems', function (): void {
    config()->set('horizon-observability.job_class_paths');
    $config = observabilityConfig();

    expect($config->problems)->toBe([])
        ->and($config->enabled)->toBeTrue()
        ->and($config->namespace)->toBe('ho')
        ->and($config->prefix())->toBe('ho_')
        ->and($config->path)->toBeNull()
        ->and($config->domain)->toBeNull()
        ->and($config->tokens)->toBe([])
        ->and($config->apiTokens)->toBe([])
        ->and($config->allowedIps)->toBe([])
        ->and($config->middleware)->toBe([])
        ->and($config->availableDuringMaintenance)->toBeTrue()
        ->and($config->exceptions)->toBe(ExceptionMode::Full)
        ->and($config->redisConnection)->toBe('horizon')
        ->and($config->workerTimeout)->toBe(1.0)
        ->and($config->scrapeTimeout)->toBe(2.0)
        ->and($config->collectors)->toBe(['horizon' => true, 'queues' => true, 'attempts' => true, 'job_classes' => true, 'batches' => null])
        ->and($config->buckets)->toBe(Config::DEFAULT_BUCKETS)
        ->and($config->queueScanLimit)->toBe(1000)
        ->and($config->maxJobClasses)->toBe(500)
        ->and($config->jobClassPaths)->toBe([app_path()])
        ->and($config->horizonUrl)->toBeNull()
        ->and($config->grafanaUrl)->toBeNull()
        ->and($config->ttsUrl)->toBeNull()
        ->and($config->liveEnabled)->toBeTrue()
        ->and($config->liveCacheTtl)->toBe(1.0)
        ->and($config->liveGrafanaUrls)->toBe([])
        ->and($config->liveToken)->toBeNull()
        ->and($config->liveStreamId)->toBe('horizon_observability')
        ->and($config->liveStreamInterval)->toBe(1.0)
        ->and($config->logChannel)->toBeNull();
});

it('reports a non-array root and uses the defaults', function (): void {
    config()->set('horizon-observability', 'broken');
    $config = Config::fromRepository(app('config'));

    expect(problemPaths($config))->toBe([''])
        ->and($config->namespace)->toBe('ho')
        ->and($config->enabled)->toBeTrue();
});

it('casts booleans and reports invalid ones', function (mixed $value, bool $expected, bool $problem): void {
    $config = observabilityConfig(['enabled' => $value]);

    expect($config->enabled)->toBe($expected)
        ->and($config->hasProblem('enabled'))->toBe($problem);
})->with([
    'true' => [true, true, false],
    'false' => [false, false, false],
    'string false' => ['false', false, false],
    'string off' => ['off', false, false],
    'string 1' => ['1', true, false],
    'int 0' => [0, false, false],
    'null is the default' => [null, true, false],
    'garbage' => ['maybe', true, true],
    'array' => [[true], true, true],
]);

it('normalises the namespace', function (mixed $value, string $expected, bool $problem): void {
    $config = observabilityConfig(['namespace' => $value]);

    expect($config->namespace)->toBe($expected)
        ->and($config->hasProblem('namespace'))->toBe($problem);
})->with([
    'default' => ['ho', 'ho', false],
    'office' => ['office', 'office', false],
    'trailing underscore stripped' => ['office_', 'office', false],
    'whitespace trimmed' => [' office ', 'office', false],
    'leading underscore' => ['_app', '_app', false],
    'empty means no prefix' => ['', '', false],
    'leading digit' => ['1app', 'ho', true],
    'dash' => ['my-app', 'ho', true],
    'colon' => ['a:b', 'ho', true],
    'underscores only' => ['__', 'ho', true],
    'not a string' => [42, 'ho', true],
]);

it('has an empty prefix for an empty namespace', function (): void {
    expect(observabilityConfig(['namespace' => ''])->prefix())->toBe('');
});

it('validates the path', function (mixed $value, ?string $expected, bool $problem): void {
    $config = observabilityConfig(['path' => $value]);

    expect($config->path)->toBe($expected)
        ->and($config->hasProblem('path'))->toBe($problem);
})->with([
    'null' => [null, null, false],
    'empty' => ['', null, false],
    'slash only' => ['/', null, false],
    'office' => ['metrics/horizon', 'metrics/horizon', false],
    'slashes trimmed' => ['/metrics/horizon/', 'metrics/horizon', false],
    'route parameter' => ['metrics/{x}', null, true],
    'closing brace' => ['metrics}', null, true],
    'dot dot' => ['metrics/../admin', null, true],
    'whitespace' => ['my metrics', null, true],
    'not a string' => [['a'], null, true],
]);

it('trims the domain', function (): void {
    expect(observabilityConfig(['domain' => ' metrics.internal '])->domain)->toBe('metrics.internal')
        ->and(observabilityConfig(['domain' => ''])->domain)->toBeNull()
        ->and(observabilityConfig(['domain' => 5])->hasProblem('domain'))->toBeTrue();
});

it('splits tokens on commas and drops short ones (fail closed)', function (): void {
    $config = observabilityConfig(['token' => ' 0123456789abcdef , tiny-secret, fedcba9876543210,0123456789abcdef,  ']);

    expect($config->tokens)->toBe(['0123456789abcdef', 'fedcba9876543210'])
        ->and(problemPaths($config))->toBe(['token'])
        ->and($config->problems[0]->message)->toContain('1 of 3 tokens')
        ->and($config->problems[0]->message)->not->toContain('tiny-secret')
        ->and($config->requiresToken('metrics'))->toBeTrue();
});

it('accepts token lists as arrays', function (): void {
    $config = observabilityConfig(['token' => ['0123456789abcdef', 7, 'fedcba9876543210']]);

    expect($config->tokens)->toBe(['0123456789abcdef', 'fedcba9876543210'])
        ->and($config->hasProblem('token'))->toBeTrue();
});

it('requires a token when every configured token is invalid', function (): void {
    $config = observabilityConfig(['token' => 'short,tiny']);

    expect($config->tokens)->toBe([])
        ->and($config->tokensFor('metrics'))->toBe([])
        ->and($config->requiresToken('metrics'))->toBeTrue()
        ->and($config->requiresToken('api'))->toBeTrue();
});

it('needs no token when none is configured', function (): void {
    $config = observabilityConfig();

    expect($config->requiresToken('metrics'))->toBeFalse()
        ->and($config->requiresToken('api'))->toBeFalse()
        ->and($config->requiresToken('other'))->toBeFalse();
});

it('uses api_token for the API endpoints when it is configured', function (): void {
    $config = observabilityConfig(['token' => '0123456789abcdef', 'api_token' => 'grafana-token-0123456789']);

    expect($config->tokensFor('metrics'))->toBe(['0123456789abcdef'])
        ->and($config->tokensFor('api'))->toBe(['grafana-token-0123456789'])
        ->and($config->requiresToken('api'))->toBeTrue();
});

it('falls back to token for the API endpoints without api_token', function (): void {
    $config = observabilityConfig(['token' => '0123456789abcdef']);

    expect($config->tokensFor('api'))->toBe(['0123456789abcdef'])
        ->and($config->requiresToken('api'))->toBeTrue();
});

it('fails closed when every api_token is invalid', function (): void {
    $config = observabilityConfig(['token' => '0123456789abcdef', 'api_token' => 'short']);

    expect($config->tokensFor('api'))->toBe([])
        ->and($config->requiresToken('api'))->toBeTrue()
        ->and($config->tokensFor('metrics'))->toBe(['0123456789abcdef']);
});

it('validates the allowed IPs (fail closed)', function (): void {
    $config = observabilityConfig(['allowed_ips' => '10.0.0.0/8, 192.168.1.10,::1, 2001:db8::/32, bogus, 10.0.0.0/33, 0.0.0.0/0, 300.1.1.1, ::1/129, 10.0.0.1/x']);

    expect($config->allowedIps)->toBe(['10.0.0.0/8', '192.168.1.10', '::1', '2001:db8::/32', '0.0.0.0/0'])
        ->and(problemPaths($config))->toBe(['allowed_ips'])
        ->and($config->problems[0]->message)->toContain('5 of 10 entries')
        ->and($config->requiresIp())->toBeTrue();
});

it('still requires an IP match when every allowed IP is invalid', function (): void {
    $config = observabilityConfig(['allowed_ips' => 'bogus']);

    expect($config->allowedIps)->toBe([])
        ->and($config->requiresIp())->toBeTrue();
});

it('needs no IP match without allowed IPs', function (): void {
    expect(observabilityConfig(['allowed_ips' => ''])->requiresIp())->toBeFalse();
});

it('keeps only middleware names', function (): void {
    $config = observabilityConfig(['middleware' => ['throttle:600,1', 5, '', null]]);

    expect($config->middleware)->toBe(['throttle:600,1'])
        ->and($config->hasProblem('middleware'))->toBeTrue()
        ->and(observabilityConfig(['middleware' => 'throttle:60,1'])->middleware)->toBe(['throttle:60,1'])
        ->and(observabilityConfig(['middleware' => 42])->middleware)->toBe([]);
});

it('parses the exception mode', function (mixed $value, ExceptionMode $expected, bool $problem): void {
    $config = observabilityConfig(['exceptions' => $value]);

    expect($config->exceptions)->toBe($expected)
        ->and($config->hasProblem('exceptions'))->toBe($problem);
})->with([
    'full' => ['full', ExceptionMode::Full, false],
    'class' => ['class', ExceptionMode::ClassOnly, false],
    'none' => ['none', ExceptionMode::None, false],
    'upper case' => ['CLASS', ExceptionMode::ClassOnly, false],
    'null' => [null, ExceptionMode::Full, false],
    'unknown' => ['verbose', ExceptionMode::Full, true],
    'not a string' => [1, ExceptionMode::Full, true],
]);

it('defaults the storage connection to horizon', function (): void {
    $config = observabilityConfig(['redis.connection' => null]);

    expect($config->redisConnection)->toBe('horizon')
        ->and($config->hasProblem('redis.connection'))->toBeFalse();
});

it('reports a missing storage connection but keeps it', function (): void {
    $config = observabilityConfig(['redis.connection' => 'missing']);

    expect($config->redisConnection)->toBe('missing')
        ->and($config->hasProblem('redis.connection'))->toBeTrue();
});

it('accepts a cluster storage connection', function (): void {
    config()->set('database.redis.clusters.metrics', [['host' => '127.0.0.1', 'port' => 7000]]);

    $config = observabilityConfig(['redis.connection' => 'metrics']);

    expect($config->redisConnection)->toBe('metrics')
        ->and($config->hasProblem('redis.connection'))->toBeFalse();
});

it('does not take the reserved keys of database.redis for connections', function (string $name): void {
    expect(observabilityConfig(['redis.connection' => $name])->hasProblem('redis.connection'))->toBeTrue();
})->with(['options', 'client', 'clusters']);

it('bounds the Redis timeouts to (0, 30]', function (mixed $value, float $expected, bool $problem): void {
    $config = observabilityConfig(['redis.worker_timeout' => $value, 'redis.scrape_timeout' => $value]);

    expect($config->workerTimeout)->toBe($problem ? 1.0 : $expected)
        ->and($config->scrapeTimeout)->toBe($problem ? 2.0 : $expected)
        ->and($config->hasProblem('redis.worker_timeout'))->toBe($problem)
        ->and($config->hasProblem('redis.scrape_timeout'))->toBe($problem);
})->with([
    'small' => [0.2, 0.2, false],
    'upper bound' => [30, 30.0, false],
    'numeric string' => ['1.5', 1.5, false],
    'zero' => [0, 0.0, true],
    'negative' => [-1, 0.0, true],
    'too long' => [30.5, 0.0, true],
    'not a number' => ['soon', 0.0, true],
    'infinite' => [INF, 0.0, true],
]);

it('reads the collector switches', function (): void {
    $config = observabilityConfig(['collectors' => ['horizon' => 'false', 'queues' => 0, 'attempts' => true, 'job_classes' => 'maybe', 'batches' => true, 'spatie' => true]]);

    expect($config->collectors)->toBe(['horizon' => false, 'queues' => false, 'attempts' => true, 'job_classes' => true, 'batches' => true])
        ->and(problemPaths($config))->toBe(['collectors.job_classes', 'collectors.spatie']);
});

it('reads the batches collector as true, false or auto', function (mixed $value, ?bool $expected, bool $problem): void {
    $config = observabilityConfig(['collectors.batches' => $value]);

    expect($config->collectors['batches'])->toBe($expected)
        ->and($config->hasProblem('collectors.batches'))->toBe($problem);
})->with([
    'auto' => [null, null, false],
    'auto string' => ['auto', null, false],
    'required' => [true, true, false],
    'off' => [false, false, false],
    'off string' => ['false', false, false],
    'garbage' => ['sometimes', null, true],
]);

it('uses the defaults for a non-array collectors value', function (): void {
    $config = observabilityConfig(['collectors' => 'all']);

    expect($config->collectors['horizon'])->toBeTrue()
        ->and($config->hasProblem('collectors'))->toBeTrue();
});

it('normalises the buckets', function (mixed $value, array $expected, bool $problem): void {
    $config = observabilityConfig(['buckets' => $value]);

    expect($config->buckets)->toBe($expected)
        ->and($config->hasProblem('buckets'))->toBe($problem);
})->with([
    'integers become floats' => [[1, 5, 10], [1.0, 5.0, 10.0], false],
    'sorted and deduplicated' => [[10, 1, 5, 1], [1.0, 5.0, 10.0], true],
    'invalid entries dropped' => [[-1, 'x', 0, 2, INF, '3'], [2.0, 3.0], true],
    'empty' => [[], Config::DEFAULT_BUCKETS, true],
    'nothing valid' => [[0, -5], Config::DEFAULT_BUCKETS, true],
    'not a list' => ['1,2,3', Config::DEFAULT_BUCKETS, true],
]);

it('bounds the scan limit and the class cap', function (): void {
    expect(observabilityConfig(['queue_scan_limit' => 0])->queueScanLimit)->toBe(0)
        ->and(observabilityConfig(['queue_scan_limit' => '50'])->queueScanLimit)->toBe(50)
        ->and(observabilityConfig(['queue_scan_limit' => -1])->queueScanLimit)->toBe(1000)
        ->and(observabilityConfig(['queue_scan_limit' => 1.5])->hasProblem('queue_scan_limit'))->toBeTrue()
        ->and(observabilityConfig(['max_job_classes' => 10000])->maxJobClasses)->toBe(10000)
        ->and(observabilityConfig(['max_job_classes' => 1])->maxJobClasses)->toBe(1)
        ->and(observabilityConfig(['max_job_classes' => 0])->maxJobClasses)->toBe(500)
        ->and(observabilityConfig(['max_job_classes' => 10001])->hasProblem('max_job_classes'))->toBeTrue();
});

it('keeps only existing job class directories', function (): void {
    $config = observabilityConfig(['job_class_paths' => [__DIR__, __DIR__.'/missing', 5]]);

    expect($config->jobClassPaths)->toBe([__DIR__])
        ->and(problemPaths($config))->toBe(['job_class_paths', 'job_class_paths'])
        ->and(observabilityConfig(['job_class_paths' => __DIR__])->jobClassPaths)->toBe([__DIR__])
        ->and(observabilityConfig(['job_class_paths' => []])->jobClassPaths)->toBe([]);
});

it('validates the URLs', function (string $key, mixed $value, ?string $expected, bool $problem): void {
    $config = observabilityConfig([$key => $value]);
    $actual = match ($key) {
        'horizon_url' => $config->horizonUrl,
        'grafana.url' => $config->grafanaUrl,
        default => $config->ttsUrl,
    };

    expect($actual)->toBe($expected)
        ->and($config->hasProblem($key))->toBe($problem);
})->with([
    'horizon url' => ['horizon_url', 'https://ops.example.com/horizon', 'https://ops.example.com/horizon', false],
    'horizon url ftp' => ['horizon_url', 'ftp://ops.example.com', null, true],
    'grafana url with query' => ['grafana.url', 'http://localhost:3000/d/horizon-observability?orgId=1', 'http://localhost:3000/d/horizon-observability?orgId=1', false],
    'grafana url relative' => ['grafana.url', '/d/horizon', null, true],
    'grafana url empty' => ['grafana.url', '', null, false],
    'tts browser' => ['grafana.tts_url', 'Browser', 'browser', false],
    'tts piper' => ['grafana.tts_url', 'http://piper:5000', 'http://piper:5000', false],
    'tts garbage' => ['grafana.tts_url', 'speak', null, true],
]);

it('validates the live settings', function (): void {
    $config = observabilityConfig([
        'live.enabled' => 'false',
        'live.cache_ttl' => 0,
        'live.stream.grafana_url' => 'http://grafana-a:3000/, bogus, https://grafana-b',
        'live.stream.token' => ' glsa_secret ',
        'live.stream.id' => 'office-live_1',
        'live.stream.interval' => '0.5',
    ]);

    expect($config->liveEnabled)->toBeFalse()
        ->and($config->liveCacheTtl)->toBe(0.0)
        ->and($config->liveGrafanaUrls)->toBe(['http://grafana-a:3000', 'https://grafana-b'])
        ->and($config->liveToken)->toBe('glsa_secret')
        ->and($config->liveStreamId)->toBe('office-live_1')
        ->and($config->liveStreamInterval)->toBe(0.5)
        ->and(problemPaths($config))->toBe(['live.stream.grafana_url']);
});

it('reports invalid live settings and uses the defaults', function (): void {
    $config = observabilityConfig([
        'live.enabled' => 'sometimes',
        'live.cache_ttl' => 11,
        'live.stream.token' => 123,
        'live.stream.id' => 'bad id',
        'live.stream.interval' => 0.4,
    ]);

    expect($config->liveEnabled)->toBeTrue()
        ->and($config->liveCacheTtl)->toBe(1.0)
        ->and($config->liveToken)->toBeNull()
        ->and($config->liveStreamId)->toBe('horizon_observability')
        ->and($config->liveStreamInterval)->toBe(1.0)
        ->and(problemPaths($config))->toBe(['live.enabled', 'live.cache_ttl', 'live.stream.token', 'live.stream.id', 'live.stream.interval']);
});

it('validates the stream id', function (string $id, bool $valid): void {
    expect(observabilityConfig(['live.stream.id' => $id])->hasProblem('live.stream.id'))->toBe(! $valid);
})->with([
    'default' => ['horizon_observability', true],
    '64 characters' => [str_repeat('a', 64), true],
    '65 characters' => [str_repeat('a', 65), false],
    'dot' => ['a.b', false],
    'empty' => ['', false],
]);

it('treats an empty live token as missing', function (): void {
    expect(observabilityConfig(['live.stream.token' => '  '])->liveToken)->toBeNull();
});

it('checks that the log channel exists', function (): void {
    config()->set('logging.channels.observability', ['driver' => 'single', 'path' => storage_path('logs/observability.log')]);

    expect(observabilityConfig(['log_channel' => 'observability'])->logChannel)->toBe('observability')
        ->and(observabilityConfig(['log_channel' => 'missing'])->logChannel)->toBeNull()
        ->and(observabilityConfig(['log_channel' => 'missing'])->hasProblem('log_channel'))->toBeTrue();
});

it('never throws, whatever the types', function (mixed $value): void {
    foreach (['enabled', 'namespace', 'path', 'domain', 'token', 'api_token', 'allowed_ips', 'middleware', 'exceptions', 'redis', 'collectors', 'buckets', 'queue_scan_limit', 'max_job_classes', 'job_class_paths', 'horizon_url', 'grafana', 'live', 'log_channel'] as $key) {
        config()->set('horizon-observability.'.$key, $value);
    }

    expect(Config::fromRepository(app('config')))->toBeInstanceOf(Config::class);
})->with([
    'object' => [new stdClass],
    'nested array' => [[['x' => ['y']]]],
    'float' => [1.5],
    'negative int' => [-7],
]);

it('casts boot-time flags safely', function (mixed $value, bool $default, bool $expected): void {
    expect(Config::flag($value, $default))->toBe($expected);
})->with([
    'true' => [true, false, true],
    'false string' => ['false', true, false],
    'yes' => ['yes', false, true],
    'zero' => [0, true, false],
    'garbage keeps the default' => ['garbage', true, true],
    'null keeps the default' => [null, false, false],
    'array keeps the default' => [[], true, true],
]);

it('redacts the secrets when dumped', function (): void {
    $config = observabilityConfig(['token' => '0123456789abcdef', 'api_token' => 'fedcba9876543210', 'live.stream.token' => 'glsa_secret']);
    $dump = print_r($config->__debugInfo(), true);

    expect($dump)->not->toContain('0123456789abcdef')
        ->and($dump)->not->toContain('fedcba9876543210')
        ->and($dump)->not->toContain('glsa_secret')
        ->and($dump)->toContain('[redacted]');
});
