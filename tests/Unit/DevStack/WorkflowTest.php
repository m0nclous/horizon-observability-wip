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

    expect(end($steps))->toBe('vendor/bin/pest --ci --exclude-group=cluster --exclude-group=e2e')
        ->and(implode("\n", $steps))->toContain('composer update --${{ matrix.stability }} --prefer-dist');
});

it('resolves the legs past the advisory block of Composer without turning off its malware block (T18 QA)', function (): void {
    $steps = workflow('tests.yml')['jobs']['tests']['steps'];
    $resolve = array_values(array_filter($steps, static fn (array $step): bool => ($step['name'] ?? null) === 'Resolve the leg'))[0] ?? [];
    $env = array_map(strval(...), $resolve['env'] ?? []);
    $narrow = ($env['COMPOSER_POLICY_ADVISORIES_BLOCK'] ?? null) === '0'
        || str_contains((string) ($resolve['run'] ?? ''), 'composer config audit.block-insecure false');

    // Composer 2.10 maps COMPOSER_NO_BLOCKING and COMPOSER_NO_SECURITY_BLOCKING to PolicyConfig::withBlockingDisabled(),
    // which also disables the malware list (on by default) for every package the leg installs, the prefer-stable legs
    // included. COMPOSER_POLICY_ADVISORIES_BLOCK=0 (2.10) or audit.block-insecure (2.9 and 2.10) lifts the advisories only.
    expect($env)->not->toHaveKey('COMPOSER_NO_BLOCKING')
        ->and($env)->not->toHaveKey('COMPOSER_NO_SECURITY_BLOCKING')
        ->and($narrow)->toBeTrue();
});

it('fails a prefer-lowest leg that does not resolve the oldest point of its shims (T18)', function (): void {
    $tests = workflow('tests.yml')['jobs']['tests'];
    $lowest = array_values(array_filter(workflowLegs($tests['strategy']['matrix']), static fn (array $leg): bool => $leg['stability'] === 'prefer-lowest'));
    $resolved = array_values(array_filter($tests['steps'], static fn (array $step): bool => ($step['name'] ?? null) === 'Resolved versions'))[0] ?? [];
    $resolve = array_values(array_filter($tests['steps'], static fn (array $step): bool => ($step['name'] ?? null) === 'Resolve the leg'))[0] ?? [];

    // Laravel 11.23 is the declared floor (the first with JobAttempted, owner decision 3); the shims below 11.27.1
    // (no ServiceProvider::optimizes()) run only there. With Pest ^3.8 (collision 8.7) the leg would resolve 11.39.1.
    expect(array_map(static fn (array $leg): string => $leg['laravel'].' => '.($leg['floor'] ?? ''), $lowest))->toBe([
        '^11.23 => laravel/framework:11.23.0 laravel/horizon:5.29.1 predis/predis:2.0.2',
        '^12.0 => laravel/framework:12.0.1 laravel/horizon:5.30.3',
        '^13.0 => laravel/framework:13.0.0 laravel/horizon:5.44.0',
    ])
        ->and($lowest[0]['pest'] ?? null)->toBe('^3.0')
        ->and(array_filter(workflowLegs($tests['strategy']['matrix']), static fn (array $leg): bool => isset($leg['floor']) && $leg['stability'] !== 'prefer-lowest'))->toBe([])
        ->and($resolve['run'] ?? '')->toContain('${{ matrix.pest && format(\'"pestphp/pest:{0}"\', matrix.pest) || \'\' }}')
        ->and($resolved['env']['FLOOR'] ?? null)->toBe('${{ matrix.floor }}')
        ->and($resolved['run'] ?? '')->toContain('for pin in $FLOOR; do')->toContain('exit 1');
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
        ->and(end($coverage['steps'])['run'] ?? null)->toContain('--exclude-group=cluster --exclude-group=e2e');
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
