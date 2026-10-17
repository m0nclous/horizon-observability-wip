<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * ADDENDUM R2.4 O5, Q9' WP8C acceptance (e2e): the queue tables (#5, one copy per supervisor) read the hidden
 * queue source #24 through the -- Dashboard -- datasource. In a real Grafana with data, every copy shows rows, while
 * #23's script hides itself and #24 in the top grid row. Runs against the stack of the CI e2e job (GRAFANA_URL,
 * DASHBOARD_UID) with Playwright; skipped without them.
 */

pest()->group('e2e');

beforeEach(function (): void {
    if (! is_string(getenv('GRAFANA_URL')) || getenv('GRAFANA_URL') === '') {
        $this->markTestSkipped('No Grafana: set GRAFANA_URL to a dashboard stack with data (the CI e2e job).');
    }
});

it('fills every copy of the queue table from the hidden source', function (): void {
    $script = <<<'SCRIPT'
        const { chromium } = await import(process.cwd() + '/node_modules/playwright/index.mjs');
        const [grafana, uid] = process.argv.slice(2);
        const browser = await chromium.launch();
        const page = await browser.newPage({ locale: 'en-US', timezoneId: 'UTC', viewport: { width: 1920, height: 2000 } });
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => { if (message.type() === 'error' && /afterRender|sayable/.test(message.text())) errors.push(message.text()); });
        await page.goto(`${grafana}/d/${uid}?orgId=1&from=now-15m&to=now&kiosk`, { waitUntil: 'networkidle', timeout: 90000 });
        // Two refresh cycles of the 10 s default
        await page.waitForTimeout(25000);
        const panels = await page.evaluate(() => [...document.querySelectorAll('[data-viz-panel-key]')].map((element) => {
          const item = element.closest('.react-grid-item');
          return {
            key: element.getAttribute('data-viz-panel-key'),
            rows: element.querySelectorAll('[role="row"]').length,
            visibility: item ? getComputedStyle(item).visibility : null,
          };
        }));
        await browser.close();
        console.log(JSON.stringify({ panels, errors }));
        SCRIPT;

    $process = new Process(['node', '--input-type=module', '-', rtrim((string) getenv('GRAFANA_URL'), '/'), getenv('DASHBOARD_UID') ?: 'horizon-observability']);
    $process->setWorkingDirectory(dirname(__DIR__, 2))->setTimeout(180)->setInput($script)->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $panels = array_column($result['panels'], null, 'key');
    $tables = array_filter($panels, static fn (string $key): bool => preg_match('/^panel-5(-clone-\d+)?$/', $key) === 1, ARRAY_FILTER_USE_KEY);

    expect($tables)->not->toBe([])
        // A header row and at least one queue
        ->and(array_filter($tables, static fn (array $table): bool => $table['rows'] < 2))->toBe([])
        ->and($panels['panel-23']['visibility'] ?? null)->toBe('hidden')
        ->and($panels['panel-24']['visibility'] ?? null)->toBe('hidden')
        ->and($result['errors'])->toBe([]);
});
