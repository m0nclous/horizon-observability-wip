<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
| T18 QA round 2 (adversarial): the "Resolved versions" step of the tests job runs as GitHub runs it (`bash -e`, and
| `bash -eo pipefail` for `shell: bash`) against a fake `composer show`, so the floor check is proven to fail a leg
| whose resolution moved and to pass the legs it should, not only to contain the right words.
*/

/**
 * The `run` script and the `env` of a named step of the tests job.
 *
 * @return array{run: string, env: array<string, string>}
 */
function workflowFloorStep(string $name): array
{
    $parsed = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/tests.yml');
    $steps = is_array($parsed) ? $parsed['jobs']['tests']['steps'] : [];
    $step = array_values(array_filter($steps, static fn (array $step): bool => ($step['name'] ?? null) === $name))[0] ?? [];

    return ['run' => (string) ($step['run'] ?? ''), 'env' => array_map(strval(...), $step['env'] ?? [])];
}

/**
 * Runs the "Resolved versions" script with FLOOR set and a `composer` on the PATH whose `show` prints $installed.
 *
 * @param  array<string, string>  $installed  package => version, in `composer show` layout
 * @return array{exit: int|null, output: string}
 */
function workflowFloorRun(string $floor, array $installed, string $shell = 'bash -e'): array
{
    $dir = sys_get_temp_dir().'/ho-floor-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $listing = '';

    foreach ($installed as $package => $version) {
        $listing .= str_pad($package, 36).str_pad($version, 8).'Description of '.$package."\n";
    }

    file_put_contents($dir.'/show.txt', $listing);
    file_put_contents($dir.'/composer', "#!/usr/bin/env bash\n[ \"\$1\" = show ] && [ \$# -eq 1 ] || { echo \"unexpected composer \$*\" >&2; exit 2; }\ncat \"\$(dirname \"\$0\")/show.txt\"\n");
    chmod($dir.'/composer', 0755);
    file_put_contents($dir.'/step.sh', workflowFloorStep('Resolved versions')['run']);

    try {
        $process = new Process([...explode(' ', $shell), $dir.'/step.sh'], $dir, ['PATH' => $dir.':'.getenv('PATH'), 'FLOOR' => $floor]);
        $process->run();

        return ['exit' => $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
    } finally {
        foreach (['show.txt', 'composer', 'step.sh'] as $file) {
            @unlink($dir.'/'.$file);
        }

        @rmdir($dir);
    }
}

/**
 * The installed versions of the L11 prefer-lowest leg on PR #20 (run 39040463193).
 *
 * @return array<string, string>
 */
function workflowFloorL11(): array
{
    return [
        'laravel/framework' => '11.23.0',
        'laravel/framework-extra' => '9.9.9',
        'laravel/horizon' => '5.29.1',
        'orchestra/testbench' => '9.5.0',
        'pestphp/pest' => '3.0.0',
        'predis/predis' => '2.0.2',
    ];
}

beforeEach(function (): void {
    if (DIRECTORY_SEPARATOR !== '/' || ! is_executable('/bin/bash') && ! is_executable('/usr/bin/bash')) {
        $this->markTestSkipped('The floor check is a bash script.');
    }
});

it('passes a prefer-lowest leg that resolved its floor and a leg without a floor (T18 QA round 2)', function (string $shell): void {
    $floor = 'laravel/framework:11.23.0 laravel/horizon:5.29.1 predis/predis:2.0.2';

    expect(workflowFloorRun($floor, workflowFloorL11(), $shell))->toMatchArray(['exit' => 0])
        ->and(workflowFloorRun('', workflowFloorL11(), $shell))->toMatchArray(['exit' => 0])
        ->and(workflowFloorRun($floor, workflowFloorL11(), $shell)['output'])
        ->toContain('laravel/framework                   11.23.0')
        ->not->toContain('laravel/framework-extra')
        ->not->toContain('::error::');
})->with(['bash -e', 'bash -eo pipefail']);

it('fails a prefer-lowest leg whose resolution moved one pin, and names the pin (T18 QA round 2)', function (string $shell): void {
    // What the advisories policy (Composer 2.10) or Pest 3.8 (collision 8.7) did to the L11 leg before round 2.
    $moved = workflowFloorRun('laravel/framework:11.23.0 laravel/horizon:5.29.1 predis/predis:2.0.2', ['laravel/framework' => '11.39.1'] + workflowFloorL11(), $shell);
    $horizon = workflowFloorRun('laravel/framework:11.23.0 laravel/horizon:5.29.1 predis/predis:2.0.2', ['laravel/horizon' => '5.30.0'] + workflowFloorL11(), $shell);

    expect($moved['exit'])->toBe(1)
        ->and($moved['output'])->toContain('::error::laravel/framework resolved 11.39.1 instead of the floor 11.23.0')
        ->and($horizon['exit'])->toBe(1)
        ->and($horizon['output'])->toContain('::error::laravel/horizon resolved 5.30.0 instead of the floor 5.29.1');
})->with(['bash -e', 'bash -eo pipefail']);

it('fails a leg where a pinned package is not installed or only a longer name matches (T18 QA round 2)', function (string $shell): void {
    $installed = workflowFloorL11();
    unset($installed['predis/predis']);
    $missing = workflowFloorRun('laravel/framework:11.23.0 predis/predis:2.0.2', $installed, $shell);

    // laravel/framework-extra 9.9.9 must not stand in for laravel/framework.
    $prefix = workflowFloorRun('laravel/framework:9.9.9', workflowFloorL11(), $shell);

    expect($missing['exit'])->toBe(1)
        ->and($missing['output'])->toContain('::error::predis/predis resolved nothing instead of the floor 2.0.2')
        ->and($prefix['exit'])->toBe(1)
        ->and($prefix['output'])->toContain('::error::laravel/framework resolved 11.23.0 instead of the floor 9.9.9');
})->with(['bash -e', 'bash -eo pipefail']);

it('keeps every prefer-lowest floor in the format the check splits: package:version pairs (T18 QA round 2)', function (): void {
    $parsed = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/tests.yml');
    $include = is_array($parsed) ? $parsed['jobs']['tests']['strategy']['matrix']['include'] : [];
    $floors = array_values(array_filter(array_column($include, 'floor')));

    expect($floors)->toHaveCount(3);

    foreach ($floors as $floor) {
        foreach (explode(' ', $floor) as $pin) {
            expect($pin)->toMatch('#^[a-z0-9-]+/[a-z0-9-]+:\d+\.\d+\.\d+$#');
        }
    }
});

it('lifts no other Composer policy anywhere in the workflows, at any env level (T18 QA round 2)', function (): void {
    // The step-level check of WorkflowTest would miss a workflow- or job-level env, a CLI flag or a policy config.
    $sources = array_map(static fn (string $file): string => (string) file_get_contents($file), glob(dirname(__DIR__, 3).'/.github/workflows/*.yml') ?: []);
    $text = implode("\n", $sources);
    $code = (string) preg_replace('/^\s*#.*$/m', '', $text);

    expect($sources)->not->toBeEmpty()
        ->and($code)->not->toMatch('/COMPOSER_NO_BLOCKING|COMPOSER_NO_SECURITY_BLOCKING|COMPOSER_POLICY_MALWARE_BLOCK|COMPOSER_POLICY:|--no-blocking|--no-security-blocking|config\s+(--\S+\s+)*policy\b/')
        ->and(substr_count($code, 'COMPOSER_POLICY_ADVISORIES_BLOCK'))->toBe(1)
        ->and(workflowFloorStep('Resolve the leg')['env'])->toBe(['COMPOSER_POLICY_ADVISORIES_BLOCK' => '0']);
});
