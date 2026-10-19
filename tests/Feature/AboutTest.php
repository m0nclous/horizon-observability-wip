<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\Versions;
use Illuminate\Support\Facades\Artisan;

/*
| The `about` section: endpoints and access mode, namespace, storage connection, client and cluster flag, collectors,
| payload-delay mode, batching driver, job-class cache state and config problems, from the configuration only: no
| Redis and no database, and never a secret.
*/

/**
 * @return array<string, string>
 */
function aboutSection(): array
{
    app()->forgetInstance(Config::class);
    Artisan::call('about', ['--only' => 'horizon_observability', '--json' => true]);

    /** @var array{horizon_observability: array<string, string>} $about */
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return $about['horizon_observability'];
}

it('shows every item of the section', function (): void {
    expect(array_keys(aboutSection()))->toBe([
        'version', 'enabled', 'endpoints', 'access', 'namespace', 'storage', 'collectors', 'payload_delay', 'batching',
        'job_class_cache', 'live', 'config_problems',
    ]);
});

it('describes the default setup', function (): void {
    $about = aboutSection();

    expect($about)->toMatchArray([
        'enabled' => 'yes',
        'endpoints' => url('horizon/observability'),
        'access' => 'locked (set HORIZON_OBSERVABILITY_TOKEN)',
        'namespace' => 'ho',
        'collectors' => 'horizon on, queues on, attempts on, job_classes on, batches auto',
        'payload_delay' => app(Versions::class)->payloadCarriesDelay() ? 'native' : 'legacy (ready_at keys)',
        'batching' => 'database (job_batches on testing), auto',
        'job_class_cache' => 'runtime (no cache file)',
        'live' => 'enabled',
        'config_problems' => '0',
    ])->and($about['storage'])->toStartWith('horizon (');
});

it('reports the configured values and the problems', function (): void {
    config()->set('horizon-observability.token', 'about-token-0123456789abcdef');
    config()->set('horizon-observability.namespace', '1bad');
    config()->set('horizon-observability.collectors.batches', false);
    config()->set('horizon-observability.live.enabled', false);
    config()->set('horizon-observability.path', 'internal/metrics');

    $about = aboutSection();
    $json = Artisan::output();

    expect($about)->toMatchArray([
        'access' => 'token',
        'namespace' => 'ho',
        'batching' => 'off',
        'live' => 'disabled',
        'config_problems' => '1',
        'endpoints' => url('internal/metrics'),
    ])->and($json)->not->toContain('about-token-0123456789abcdef');
});

it('flags a cluster storage connection and never connects', function (): void {
    config()->set('horizon-observability.redis.connection', 'metrics');
    config()->set('database.redis.clusters.metrics', [['host' => '127.0.0.1', 'port' => 1]]);
    app()->forgetInstance(RedisClients::class);

    expect(aboutSection()['storage'])->toEndWith(', cluster');
});

it('reads a job class cache file', function (): void {
    $path = app()->bootstrapPath('cache/horizon-observability.php');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, "<?php\n\nreturn ['version' => 1, 'generated_at' => 1792201716, 'classes' => []];\n");

    try {
        expect(aboutSection()['job_class_cache'])->toBe('cached '.gmdate('Y-m-d H:i:s', 1792201716).' UTC');
    } finally {
        @unlink($path);
    }
});
