<?php

declare(strict_types=1);
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Live\Captions;

/*
| The independence guards of the dashboard truth suite (T17, design sections 5.2 and 7.1), group grafana: the
| expectations restate documented semantics and never the dashboard's PromQL, so a spec cannot copy a defect it is
| meant to find.
|
| - Expect/** uses nothing of DF\HorizonObservability\Grafana\* (the renderer and its queries) or Live\Captions;
|   Metrics\Metric (names and labels) is allowed.
| - Expect/** holds no PromQL in its string literals (functions, aggregations with by/without, range selectors,
|   offsets, label matchers).
| - No file of the suite uses tests/Grafana/Support/Promtool, whose interpolate() maps ${x:text} to All and every
|   variable to .* (the refuted round-3 probe).
*/

pest()->group('grafana');

/**
 * @return list<string>
 */
function dashboardTruthFiles(string $directory): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * The fully qualified names a PHP file refers to: its use statements and every qualified name.
 *
 * @return list<string>
 */
function dashboardTruthNames(string $file): array
{
    $names = [];

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $names[] = ltrim($token[1], '\\');
        }
    }

    return $names;
}

it('keeps the expectation engine away from the renderer and the live captions', function (): void {
    $offending = [];

    foreach (dashboardTruthFiles(__DIR__.'/Expect') as $file) {
        foreach (dashboardTruthNames($file) as $name) {
            if (str_starts_with($name, 'DF\HorizonObservability\Grafana\\') || str_starts_with($name, Captions::class)
                || str_starts_with($name, 'DF\HorizonObservability\Tests\Grafana\\')) {
                $offending[] = basename($file).': '.$name;
            }
        }
    }

    expect(dashboardTruthFiles(__DIR__.'/Expect'))->not->toBeEmpty()
        ->and($offending)->toBe([]);
});

it('keeps PromQL out of the expectation engine', function (): void {
    $tokens = [
        'a PromQL function call' => '/\b(?:sum|min|max|avg|count|rate|irate|increase|delta|idelta|deriv|resets|changes|last_over_time|min_over_time|max_over_time|avg_over_time|sum_over_time|count_over_time|histogram_quantile|clamp_min|clamp_max|label_replace|label_join|count_values|topk|bottomk|absent|vector|timestamp|group)\s*\(/',
        'an aggregation clause' => '/\b(?:by|without)\s*\(/',
        'a range selector or subquery' => '/\[\s*(?:\d+[smhdwy]|\$__\w+)(?::[^\]]*)?\]/',
        'an offset or @ modifier' => '/\boffset\s+-?\d+[smhd]|\s@\s*(?:\d|start\(|end\(|\$)/',
        'a label matcher' => '/\{[^{}]*\b[a-z_]+\s*(?:=~|!~|!=)\s*"/',
        'a binary operator modifier' => '/\bon\s*\(|\bignoring\s*\(|\bgroup_(?:left|right)\b/',
    ];
    $offending = [];

    foreach (dashboardTruthFiles(__DIR__.'/Expect') as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            foreach ($tokens as $what => $pattern) {
                if (preg_match($pattern, $token[1]) === 1) {
                    $offending[] = sprintf('%s:%d: %s in %s', basename($file), $token[2], $what, $token[1]);
                }
            }
        }
    }

    expect($offending)->toBe([]);
});

it('never uses the promtool interpolation of the Grafana tests', function (): void {
    $offending = [];

    foreach (dashboardTruthFiles(__DIR__) as $file) {
        $source = (string) file_get_contents($file);

        if ($file !== __FILE__ && (str_contains($source, 'Grafana\Support\Promtool') || str_contains($source, 'Promtool::interpolate'))) {
            $offending[] = substr($file, strlen(__DIR__) + 1);
        }
    }

    expect($offending)->toBe([]);
});

it('detects what it guards against', function (): void {
    $probe = tempnam(sys_get_temp_dir(), 'ho-t17-independence');
    file_put_contents($probe, "<?php\nuse DF\\HorizonObservability\\Grafana\\Promql;\n\$expr = 'sum by (queue) (last_over_time(ho_queue_jobs_total{status=~\"completed\"}[10m]))';\n");

    try {
        $names = dashboardTruthNames($probe);
        $strings = array_values(array_filter(token_get_all((string) file_get_contents($probe)), static fn (string|array $token): bool => is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING));

        expect($names)->toContain(Promql::class)
            ->and(preg_match('/\b(?:by|without)\s*\(/', $strings[0][1]))->toBe(1)
            ->and(preg_match('/\[\s*(?:\d+[smhdwy]|\$__\w+)(?::[^\]]*)?\]/', $strings[0][1]))->toBe(1);
    } finally {
        unlink($probe);
    }
});
