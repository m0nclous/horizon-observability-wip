<?php

declare(strict_types=1);

/*
| Imports a render's dashboard into a Grafana of the stack by API (design section 3 STACK): the dashboard JSON as
| rendered, with only `uid` and `id` changed (uid `t17-<mode>`, or `t17m-<mode>-<mutant>` for a mutant), so every
| case loads exactly what users provision.
|
|   php tests/DashboardTruth/stack/import.php <grafana url> <dashboard.json> <uid>
|
| Admin credentials (admin:admin, the stack's) are used for the API only; the pages load as the anonymous Viewer.
*/

[$script, $url, $file, $uid] = $argv + [null, null, null, null];

if (! is_string($url) || ! is_string($file) || ! is_string($uid) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $uid) !== 1) {
    fwrite(STDERR, "usage: php import.php <grafana url> <dashboard.json> <uid>\n");
    exit(2);
}

$dashboard = json_decode((string) file_get_contents($file), true);

if (! is_array($dashboard)) {
    fwrite(STDERR, $file." is not a dashboard JSON\n");
    exit(1);
}

$dashboard['uid'] = $uid;
$dashboard['id'] = null;
$body = json_encode(['dashboard' => $dashboard, 'overwrite' => true, 'folderUid' => '', 'message' => 'T17 dashboard truth'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

for ($attempt = 1; ; $attempt++) {
    $curl = curl_init(rtrim($url, '/').'/api/dashboards/db');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => 'admin:admin',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    if ($status === 200) {
        $result = json_decode((string) $response, true);

        if (is_array($result) && ($result['uid'] ?? null) === $uid) {
            fwrite(STDOUT, sprintf("imported %s as %s on %s\n", basename(dirname($file, 2)).'/'.basename($file), $uid, $url));
            exit(0);
        }
    }

    if ($attempt >= 5) {
        fwrite(STDERR, sprintf("import of %s into %s failed: %d %s\n", $file, $url, $status, is_string($response) ? $response : ''));
        exit(1);
    }

    usleep(500_000 * $attempt);
}
