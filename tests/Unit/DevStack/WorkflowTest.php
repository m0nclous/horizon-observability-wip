<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
| The CI workflow (Arch 8.5, ADDENDUM Q9' WP9; T11): triggers, the test matrix as GitHub expands it, both Grafana
| versions in the grafana and e2e jobs, and the static and dist gates.
*/

/**
 * @return array<string, mixed>
 */
function workflow(string $name): array
{
    $parsed = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/'.$name);

    return is_array($parsed) ? $parsed : [];
}

/**
 * The legs of a job matrix as GitHub expands it: the cartesian product of the base keys minus `exclude`, then every
 * `include` object is merged into each leg whose base values it does not change, or becomes a leg of its own.
 *
 * @param  array<string, mixed>  $matrix
 * @return list<array<string, string>>
 */
function workflowLegs(array $matrix): array
{
    $base = array_diff_key($matrix, ['include' => true, 'exclude' => true]);
    $legs = [[]];

    foreach ($base as $key => $values) {
        $next = [];

        foreach ($legs as $leg) {
            foreach ($values as $value) {
                $next[] = [...$leg, $key => (string) $value];
            }
        }

        $legs = $next;
    }

    foreach ($matrix['exclude'] ?? [] as $exclude) {
        $legs = array_values(array_filter($legs, static fn (array $leg): bool => array_intersect_assoc(array_map(strval(...), $exclude), $leg) !== array_map(strval(...), $exclude)));
    }

    $count = count($legs);

    foreach ($matrix['include'] ?? [] as $include) {
        $include = array_map(strval(...), $include);
        $merged = false;

        for ($i = 0; $i < $count; $i++) {
            $original = array_intersect_key($legs[$i], $base);

            if (array_intersect_key($include, $original) === array_intersect_key($original, $include)) {
                $legs[$i] = [...$legs[$i], ...array_diff_key($include, $original)];
                $merged = true;
            }
        }

        if (! $merged) {
            $legs[] = $include;
        }
    }

    return $legs;
}

it('runs on master, claude branches, pull requests, by hand and weekly', function (): void {
    $on = workflow('tests.yml')['on'];

    expect($on['push']['branches'])->toBe(['master', 'claude/**'])
        ->and($on)->toHaveKeys(['pull_request', 'workflow_dispatch'])
        ->and($on['schedule'])->toBe([['cron' => '23 5 * * 1']])
        ->and(workflow('tests.yml')['permissions'])->toBe(['contents' => 'read'])
        ->and(workflow('tests.yml')['concurrency']['cancel-in-progress'])->toBeTrue()
        // A push must not cancel the weekly e2e run of the same branch.
        ->and(workflow('tests.yml')['concurrency']['group'])->toBe('tests-${{ github.ref }}-${{ github.event_name }}');
});

it('expands the test matrix to the 10 base and 6 targeted legs of Arch 8.5', function (): void {
    $legs = workflowLegs(workflow('tests.yml')['jobs']['tests']['strategy']['matrix']);
    $lowest = array_values(array_filter($legs, static fn (array $leg): bool => $leg['stability'] === 'prefer-lowest'));

    expect($legs)->toHaveCount(16)
        ->and(array_filter($legs, static fn (array $leg): bool => ! isset($leg['testbench'])))->toBe([])
        ->and(array_filter($legs, static fn (array $leg): bool => $leg['php'] === '8.2' && str_contains($leg['laravel'], '13')))->toBe([])
        ->and(array_filter($legs, static fn (array $leg): bool => $leg['php'] === '8.5' && str_contains($leg['laravel'], '11')))->toBe([])
        ->and(array_map(static fn (array $leg): string => $leg['php'].' '.$leg['laravel'].' '.$leg['testbench'].' '.$leg['client'].' '.$leg['redis'], $lowest))->toBe([
            '8.2 ^11.23 ^9.5 predis redis:6.2-alpine',
            '8.2 ^12.0 ^10.0 phpredis redis:6.2-alpine',
            '8.3 ^13.0 ^11.0 phpredis valkey/valkey:7.2-alpine',
        ])
        ->and(array_values(array_unique(array_column($legs, 'client'))))->toBe(['phpredis', 'predis'])
        ->and(array_values(array_unique(array_column($legs, 'redis'))))->toEqualCanonicalizing(['redis:7.4-alpine', 'redis:6.2-alpine', 'valkey/valkey:7.2-alpine', 'valkey/valkey:8-alpine', 'redis:8-alpine']);
});

it('runs the suite without the cluster and e2e groups on every leg', function (): void {
    $steps = array_column(workflow('tests.yml')['jobs']['tests']['steps'], 'run');

    expect(end($steps))->toBe('vendor/bin/pest --ci --exclude-group=cluster,e2e')
        ->and(implode("\n", $steps))->toContain('composer update --${{ matrix.stability }} --prefer-dist');
});

it('checks the Grafana assets and the e2e flow on Grafana 12.4.13 and 13.2.4', function (string $job): void {
    $definition = workflow('tests.yml')['jobs'][$job];

    expect($definition['strategy']['matrix']['grafana'])->toBe(['grafana/grafana:12.4.13', 'grafana/grafana:13.2.4'])
        ->and($definition['env']['GRAFANA_IMAGE'])->toBe('${{ matrix.grafana }}');
})->with(['grafana', 'e2e']);

it('runs the e2e job weekly and by hand only, with the dedupe gate, screenshots and the perf report', function (): void {
    $e2e = workflow('tests.yml')['jobs']['e2e'];
    $runs = implode("\n", array_column($e2e['steps'], 'run'));

    expect($e2e['if'])->toBe("github.event_name == 'schedule' || github.event_name == 'workflow_dispatch'")
        ->and($runs)->toContain('prometheus.replicas.yml')
        ->toContain('workbench:simulate-load --continuous --duration=600')
        ->toContain('node bin/screenshot.mjs')
        ->toContain('node bin/perf.mjs compare')
        ->toContain('--budget docker/perf/budget.json');
});

it('backfills the perf data with the metric names of the mounted dashboard', function (): void {
    $runs = implode("\n", array_column(workflow('tests.yml')['jobs']['e2e']['steps'], 'run'));
    $mounted = (string) file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/grafana/legacy/queues.json');

    // Until T13 the pilot dashboard (office_*) is mounted, so the weekly report must measure the legacy schema.
    expect($runs)->toContain("grep -q 'office_queue_jobs_total' build/dashboard.json")
        ->toContain('backfill.sh --profile=medium --hours=26 --schema="$PERF_SCHEMA"')
        ->toContain('workbench:perf-exporter --profile=medium --schema="$PERF_SCHEMA"')
        ->and($mounted)->toContain('office_queue_jobs_total');
});

it('lints the golden exposition with promtool in the coverage job', function (): void {
    $coverage = workflow('tests.yml')['jobs']['coverage'];

    expect($coverage['env']['PROMTOOL'] ?? null)->toBe('docker run --rm -i --entrypoint promtool quay.io/prometheus/prometheus:v3.15.0')
        ->and(end($coverage['steps'])['run'] ?? null)->toContain('--exclude-group=cluster,e2e');
});

it('gates style, types and composer.json in the static job', function (): void {
    $runs = array_column(workflow('tests.yml')['jobs']['static']['steps'], 'run');

    expect($runs)->toContain(
        'composer validate --strict',
        'composer normalize --dry-run',
        'vendor/bin/pint --test',
        'vendor/bin/rector --dry-run',
        'vendor/bin/phpstan analyse --memory-limit=1G --error-format=github',
    );
});

it('allows only the package files in the dist archive (C25)', function (): void {
    $run = implode("\n", array_column(workflow('tests.yml')['jobs']['dist']['steps'], 'run'));

    expect($run)->toContain('git archive HEAD')
        ->toContain("allowed='^(src/|config/|routes/|resources/|composer\\.json$|LICENSE$|README\\.md$|CHANGELOG\\.md$|UPGRADE\\.md$)'");
});

it('pins every action to a major version', function (string $file): void {
    $uses = [];

    foreach (workflow($file)['jobs'] as $job) {
        foreach ($job['steps'] as $step) {
            if (isset($step['uses'])) {
                $uses[] = $step['uses'];
            }
        }
    }

    expect($uses)->not->toBeEmpty()
        ->each->toMatch('/^[\w.-]+\/[\w.-]+@v\d+$/');
})->with(['tests.yml', 'release.yml']);

it('releases from a version tag with its CHANGELOG section', function (): void {
    $release = workflow('release.yml');

    expect($release['on'])->toBe(['push' => ['tags' => ['v*.*.*']]])
        ->and(implode("\n", array_column($release['jobs']['release']['steps'], 'run')))
        ->toContain('CHANGELOG.md')
        ->toContain('composer test:grafana')
        ->toContain('gh release create');
});
