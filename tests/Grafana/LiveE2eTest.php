<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/*
 * Q9' WP8D acceptance (e2e) on Grafana 12.4.13 and 13.2.4: the committed poll render shows the live value of its tiles
 * within one dashboard refresh, the committed stream render within 2 s while horizon-observability:stream pushes, and
 * neither shows a duplicate field across the refreshes (tests/Grafana/Support/live-e2e.mjs compares the tiles with
 * GET /live four times a second). Runs against the stack of the CI e2e job: GRAFANA_URL (admin access through
 * GRAFANA_AUTH, default admin:admin) and APP_URL with Horizon running; skipped without them. The test imports the
 * render under its own uid, creates the stream service account with docker/grafana/bootstrap-live.sh and runs the
 * stream command itself, on the application's Redis: REDIS_HOST and REDIS_PORT as the tests see them, or E2E_REDIS_HOST
 * and E2E_REDIS_PORT when the stack's Redis is another one.
 */

pest()->group('e2e');

beforeEach(function (): void {
    foreach (['GRAFANA_URL', 'APP_URL'] as $variable) {
        if (! is_string(getenv($variable)) || getenv($variable) === '') {
            $this->markTestSkipped('No live stack: set GRAFANA_URL and APP_URL (the CI e2e job).');
        }
    }
});

/**
 * Imports the committed render of $mode under the uid horizon-observability-e2e-<mode> (provisioned dashboards cannot
 * be overwritten) and returns the uid.
 */
function importLiveRender(LiveMode $mode): string
{
    $dashboard = json_decode((string) file_get_contents(Rendered::directory($mode).'/'.Render::DASHBOARD), true, flags: JSON_THROW_ON_ERROR);
    $uid = 'horizon-observability-e2e-'.$mode->value;
    $dashboard['uid'] = $uid;
    $dashboard['id'] = null;
    [$user, $password] = explode(':', (string) (getenv('GRAFANA_AUTH') ?: 'admin:admin'), 2);

    Http::withBasicAuth($user, $password)
        ->post(rtrim((string) getenv('GRAFANA_URL'), '/').'/api/dashboards/db', ['dashboard' => $dashboard, 'overwrite' => true, 'message' => 'live e2e'])
        ->throw();

    return $uid;
}

/**
 * Runs live-e2e.mjs and returns its report.
 *
 * @return array<string, mixed>
 */
function runLiveE2e(string $uid, LiveMode $mode): array
{
    $process = new Process([
        'node', __DIR__.'/Support/live-e2e.mjs',
        '--grafana', rtrim((string) getenv('GRAFANA_URL'), '/'),
        '--app', rtrim((string) getenv('APP_URL'), '/'),
        '--uid', $uid,
        '--mode', $mode->value,
        '--seconds', '20',
    ]);
    $process->setWorkingDirectory(dirname(__DIR__, 2))->setTimeout(240)->run();

    $report = json_decode($process->getOutput(), true);

    expect($report)->toBeArray($process->getOutput().$process->getErrorOutput());

    return $report;
}

it('shows the live values of the poll tiles within one dashboard refresh', function (): void {
    $report = runLiveE2e(importLiveRender(LiveMode::Poll), LiveMode::Poll);

    expect($report['failures'])->toBe([])
        ->and($report['refreshMs'])->toBe(5000)
        ->and($report['samples'])->toBeGreaterThan(40);
});

it('shows the live values of the stream tiles within 2 s while the stream command pushes', function (): void {
    $root = dirname(__DIR__, 2);
    $tokenFile = sys_get_temp_dir().'/ho-live-token-'.bin2hex(random_bytes(4));
    $grafana = rtrim((string) getenv('GRAFANA_URL'), '/');
    $bootstrap = new Process(['sh', $root.'/docker/grafana/bootstrap-live.sh'], $root, [
        'GRAFANA_URL' => $grafana,
        'GRAFANA_AUTH' => getenv('GRAFANA_AUTH') ?: 'admin:admin',
        'LIVE_TOKEN_FILE' => $tokenFile,
    ]);
    $bootstrap->setTimeout(240)->mustRun();
    $redis = array_filter(['REDIS_HOST' => getenv('E2E_REDIS_HOST'), 'REDIS_PORT' => getenv('E2E_REDIS_PORT')], static fn (mixed $value): bool => is_string($value) && $value !== '');
    // The application's environment, not the test's: no per-process key prefix (TestCase) and no testing environment
    $stream = new Process(['php', 'vendor/bin/testbench', 'horizon-observability:stream', '--max-time=180'], $root, [
        'REDIS_PREFIX' => false,
        'HORIZON_PREFIX' => false,
        'APP_ENV' => false,
        'HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL' => $grafana,
        'HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN' => trim((string) file_get_contents($tokenFile)),
        ...$redis,
    ]);
    $stream->setTimeout(240)->start();

    try {
        $uid = importLiveRender(LiveMode::Stream);
        // The leader lock of an earlier run expires within 5 s
        usleep(8_000_000);
        $report = runLiveE2e($uid, LiveMode::Stream);
    } finally {
        // SIGTERM: the command finishes its tick and releases the leader lock
        $stream->stop(10);
        @unlink($tokenFile);
    }

    expect($report['failures'])->toBe([], $stream->getOutput().$stream->getErrorOutput())
        ->and($report['tilesFrames'])->toBeGreaterThan(9)
        ->and($report['largestTilesGapMs'])->toBeLessThanOrEqual(2000);
});
