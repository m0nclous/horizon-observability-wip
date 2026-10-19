<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Live\LivePanels;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/*
 * T13 QA round 2 (adversarial), e2e on the stack of the CI e2e job (GRAFANA_URL with admin access, APP_URL, Horizon
 * and load running, a Prometheus datasource with uid "prometheus"); skipped without it. Run on Grafana 12.4.13 and
 * 13.2.4 in the QA round 2 stack, both fail:
 *
 * - With a Job filter the poll render's Completed caption reads "RPM: —" where the --live=off render shows the RPM of
 *   the selected class ("RPM: 40", "RPM: 49"): the snapshot has no throughput with a Job or Batch filter, and unlike
 *   Pending and Delayed (merge falls back to the Prometheus value) the caption has no fallback. ADDENDUM R4.1 does not
 *   list this deviation (owner decision 9 accepted only the listed ones).
 * - When the snapshot call fails, the Pending and Completed tiles show their PromQL expression as the caption.
 */

pest()->group('e2e');

beforeEach(function (): void {
    foreach (['GRAFANA_URL', 'APP_URL'] as $variable) {
        if (! is_string(getenv($variable)) || getenv($variable) === '') {
            $this->markTestSkipped('No live stack: set GRAFANA_URL and APP_URL (the CI e2e job).');
        }
    }
});

function adversarialGrafana(): PendingRequest
{
    [$user, $password] = explode(':', (string) (getenv('GRAFANA_AUTH') ?: 'admin:admin'), 2);

    return Http::withBasicAuth($user, $password)->baseUrl(rtrim((string) getenv('GRAFANA_URL'), '/'));
}

/**
 * Imports the committed render of $mode under $uid, with $edit applied to its JSON text.
 */
function adversarialImport(LiveMode $mode, string $uid, ?Closure $edit = null): string
{
    $json = (string) file_get_contents(Rendered::directory($mode).'/'.Render::DASHBOARD);
    $dashboard = json_decode($edit instanceof Closure ? $edit($json) : $json, true, flags: JSON_THROW_ON_ERROR);
    $dashboard['uid'] = $uid;
    $dashboard['id'] = null;
    $dashboard['title'] = 'Queues '.$uid;
    adversarialGrafana()->post('/api/dashboards/db', ['dashboard' => $dashboard, 'overwrite' => true])->throw();

    return $uid;
}

/**
 * The text of the Overview tiles of dashboard $uid with the URL query $query, after two refreshes.
 *
 * @return array<string, string> panel key => text
 */
function adversarialTiles(string $uid, string $query = ''): array
{
    $script = <<<'SCRIPT'
        const { chromium } = await import(process.cwd() + '/node_modules/playwright/index.mjs');
        const [url] = process.argv.slice(2);
        const browser = await chromium.launch();
        const page = await browser.newPage({ locale: 'en-US', timezoneId: 'UTC', viewport: { width: 1920, height: 900 } });
        await page.goto(url, { waitUntil: 'networkidle', timeout: 90000 });
        await page.waitForTimeout(12000);
        const tiles = await page.evaluate(() => Object.fromEntries([...document.querySelectorAll('[data-viz-panel-key]')]
          .filter((element) => /^panel-(2|3|8|9)$/.test(element.getAttribute('data-viz-panel-key')))
          .map((element) => [element.getAttribute('data-viz-panel-key'), element.innerText.replace(/\n/g, ' | ')])));
        await browser.close();
        console.log(JSON.stringify(tiles));
        SCRIPT;

    $url = rtrim((string) getenv('GRAFANA_URL'), '/').'/d/'.$uid.'?orgId=1&from=now-15m&to=now&kiosk'.$query;
    $process = new Process(['node', '--input-type=module', '-', $url]);
    $process->setWorkingDirectory(dirname(__DIR__, 2))->setTimeout(180)->setInput($script)->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The caption line of a stat tile's text ("Completed | RPM: 40 | 225").
 */
function adversarialCaption(string $text): string
{
    return explode(' | ', $text)[1] ?? '';
}

it('keeps the RPM of the selected class on the Completed tile of the poll render', function (): void {
    // The class with the most completed jobs in the last 5 minutes, from Prometheus through Grafana
    $top = adversarialGrafana()->get('/api/datasources/proxy/uid/prometheus/api/v1/query', [
        'query' => 'topk(1, sum by (job_class) (increase(ho_queue_jobs_total{status="completed"}[5m])))',
    ])->throw()->json('data.result.0.metric.job_class');

    expect($top)->toBeString();

    $query = '&var-job_class='.rawurlencode((string) $top);
    $off = adversarialTiles(adversarialImport(LiveMode::Off, 'ho-adv-r2-off'), $query);
    $poll = adversarialTiles(adversarialImport(LiveMode::Poll, 'ho-adv-r2-poll'), $query);

    expect(adversarialCaption($off['panel-8'] ?? ''))->toMatch('/^RPM: [1-9]\d*$/')
        ->and(adversarialCaption($poll['panel-8'] ?? ''))->toMatch('/^RPM: \d+$/', 'off: '.($off['panel-8'] ?? '').'; poll: '.($poll['panel-8'] ?? ''));
});

it('shows no PromQL as a tile caption when the snapshot call fails', function (): void {
    // A path under the package base that is no snapshot route: as with live.enabled = false or an unreachable API
    $uid = adversarialImport(LiveMode::Poll, 'ho-adv-r2-nolive', static fn (string $json): string => str_replace(
        LivePanels::URL.'?',
        LivePanels::URL.'-unavailable?',
        $json,
    ));
    $tiles = adversarialTiles($uid);

    expect($tiles)->toHaveKeys(['panel-2', 'panel-8'])
        ->and(adversarialCaption($tiles['panel-2']))->not->toMatch('/max by|sum\(|_over_time/')
        ->and(adversarialCaption($tiles['panel-8']))->not->toMatch('/max by|sum\(|_over_time/');
});
