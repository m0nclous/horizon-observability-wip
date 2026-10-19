<?php

declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/*
| T14 QA round 1 (adversarial), e2e against a running `install --docker` stack (FIRST_DASHBOARD_GRAFANA_URL, e.g.
| http://localhost:3000, admin/admin as written by the stack); skipped without it.
|
| The stack makes the rendered dashboard Grafana's home page (GF_DASHBOARDS_DEFAULT_HOME_DASHBOARD_PATH) and install
| ends with "Open http://localhost:3000". Grafana 12.4.13 serves the home page as the dashboard itself (uid
| horizon-observability). Grafana 13.2.4 redirects it to a copy, /d/default-home-dashboard/queues: the alert rules
| link their state changes to the dashboard uid horizon-observability (__dashboardUid__), so the built-in "Annotations
| & Alerts" layer of the home page shows none of them there (0 annotations for default-home-dashboard, 1 for
| horizon-observability with "Horizon is down" pending, seen on 13.2.4), and a bookmark of the opened page names a
| dashboard that exists only as the home page. Measured with the stack of `install --docker --grafana=12` and the same
| files with the 13.2.4 image.
*/

pest()->group('e2e');

beforeEach(function (): void {
    $url = getenv('FIRST_DASHBOARD_GRAFANA_URL');

    if (! is_string($url) || $url === '') {
        $this->markTestSkipped('No install --docker stack: set FIRST_DASHBOARD_GRAFANA_URL.');
    }

    $this->grafana = rtrim($url, '/');
});

function adversarialStackGrafana(string $base): PendingRequest
{
    return Http::baseUrl($base)->withBasicAuth('admin', 'admin')->acceptJson()->timeout(10);
}

it('opens the provisioned dashboard as the home page on every Grafana version', function (): void {
    $home = adversarialStackGrafana($this->grafana)->get('/api/dashboards/home')->json();
    $uid = is_array($home) ? ($home['dashboard']['uid'] ?? null) : null;
    $redirect = is_array($home) ? ($home['redirectUri'] ?? null) : null;

    expect($uid === 'horizon-observability' || (is_string($redirect) && str_starts_with($redirect, '/d/horizon-observability/')))
        ->toBeTrue('home: '.json_encode($home === null ? null : ['uid' => $uid, 'redirectUri' => $redirect]));
});
