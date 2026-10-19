<?php

declare(strict_types=1);

use DF\HorizonObservability\Console\DemoCommand;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Tests\Support\Apps;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\HorizonServiceProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

/*
| C25: the pilot registered its load test (queue-metrics:load-test with its job) in every installing application and
| shipped it in the dist archive. The load generator now lives in the workbench, which is autoload-dev and
| export-ignored; the CI dist job checks the real `git archive`.
*/

/**
 * The paths `git archive` would export from the working tree (tracked and new files, export-ignore applied), or null
 * without git.
 *
 * @return list<string>|null
 */
function simulationExportedPaths(): ?array
{
    $root = dirname(__DIR__, 3);
    $list = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $root);

    if ($list->run() !== 0) {
        return null;
    }

    $paths = array_values(array_filter(explode("\0", $list->getOutput()), static fn (string $path): bool => $path !== '' && is_file($root.'/'.$path)));
    // git archive skips a whole directory marked export-ignore, so every parent directory is checked too.
    $candidates = [];

    foreach ($paths as $path) {
        $parts = explode('/', $path);
        $counter = count($parts);

        for ($depth = 1; $depth <= $counter; $depth++) {
            $candidates[implode('/', array_slice($parts, 0, $depth))] = true;
        }
    }

    $check = new Process(['git', 'check-attr', '-z', '--stdin', 'export-ignore'], $root);
    $check->setInput(implode("\0", array_keys($candidates))."\0");

    if ($check->run() !== 0) {
        return null;
    }

    $fields = explode("\0", $check->getOutput());
    $ignored = [];
    $counter = count($fields);

    for ($i = 0; $i + 2 < $counter; $i += 3) {
        if ($fields[$i + 2] === 'set') {
            $ignored[$fields[$i]] = true;
        }
    }

    $exported = array_values(array_filter($paths, static function (string $path) use ($ignored): bool {
        $parts = explode('/', $path);
        $counter = count($parts);

        for ($depth = 1; $depth <= $counter; $depth++) {
            if (isset($ignored[implode('/', array_slice($parts, 0, $depth))])) {
                return false;
            }
        }

        return true;
    }));

    sort($exported);

    return $exported;
}

it('registers no load generator from the package (C25)', function (): void {
    // Command name => class of every command the package's own classes provide.
    $package = Apps::inspect(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        [],
        static fn (Application $app): array => array_map(
            static fn (Command $command): string => $command::class,
            array_filter($app->make(Kernel::class)->all(), static fn (Command $command): bool => str_starts_with($command::class, 'DF\\HorizonObservability\\')),
        ),
    );

    // The onboarding demo (owner decision 12, T14) is the one exception: a bounded burst that refuses in production.
    expect($package)->not->toBeEmpty()
        ->and(array_keys($package))->each->toStartWith('horizon-observability:')
        ->and(array_filter(array_keys($package), static fn (string $name): bool => preg_match('/load|simulat/i', $name) === 1))->toBe([])
        ->and(array_values(array_filter(array_keys($package), static fn (string $name): bool => str_contains($name, 'demo'))))->toBe(['horizon-observability:demo']);
});

it('keeps the load generator in the workbench only (C25)', function (): void {
    $sources = array_map(
        static fn (SplFileInfo $file): string => $file->getRelativePathname(),
        iterator_to_array(Finder::create()->files()->in(dirname(__DIR__, 3).'/src')->name('/(LoadTest|Simulat|Demo)/')->sortByName(), false),
    );

    expect($this->app->make(Kernel::class)->all())->toHaveKey('workbench:simulate-load')
        ->and($sources)->toBe(['Console/DemoCommand.php', 'Demo/DemoJob.php']);
});

it('ships only a demo that refuses to run in production (C25, owner decision 12)', function (): void {
    $this->app['env'] = 'production';

    expect(Artisan::call('horizon-observability:demo'))->toBe(1)
        ->and(Artisan::output())->toContain('refuses to run in production')
        ->and(DemoCommand::MAX_JOBS)->toBe(1000);
});

it('exports only the package files in the dist archive (C25)', function (): void {
    $exported = simulationExportedPaths();

    if ($exported === null) {
        $this->markTestSkipped('git is not available');
    }

    $outside = array_values(array_filter(
        $exported,
        static fn (string $path): bool => preg_match('#^(src|config|routes|resources)/#', $path) !== 1
            && ! in_array($path, ['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'UPGRADE.md'], true),
    ));

    expect($outside)->toBe([])
        ->and($exported)->toContain('composer.json', 'LICENSE', 'src/HorizonObservabilityServiceProvider.php')
        ->and(array_filter($exported, static fn (string $path): bool => str_contains($path, 'Simulat') || str_starts_with($path, 'workbench/')))->toBe([]);
});
