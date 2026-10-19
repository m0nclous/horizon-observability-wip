<?php

declare(strict_types=1);

use DF\HorizonObservability\Discovery\JobClassFinder;
use DF\HorizonObservability\Tests\Integration\Discovery\DiscoveryFixtures;
use DF\HorizonObservability\Tests\Integration\Discovery\TemporaryClasses;
use Symfony\Component\Process\Process;
use Workbench\App\Discovery\Jobs\ImportChunk;

/*
| Job class discovery over real files: the workbench fixture matrix, and temporary class files for the cases the
| workbench cannot hold. "Never included" is checked in a separate PHP process, so classes other tests (e.g. the
| architecture tests) autoloaded in this process cannot hide an include.
*/

/**
 * Run the finder over the workbench fixtures in a fresh PHP process.
 *
 * @return array{exit: int, error: string, classes: list<string>, included: list<string>}
 */
function discoverInSeparateProcess(bool $preloadHelpers): array
{
    $root = dirname(__DIR__, 3);
    $script = sprintf(<<<'PHP'
        require %s;
        if (%s) {
            require %s; // as Composer's "files" autoload would
        }
        $classes = (new DF\HorizonObservability\Discovery\JobClassFinder)->find([%s]);
        echo json_encode(['classes' => $classes, 'included' => get_included_files()]);
        PHP,
        var_export($root.'/vendor/autoload.php', true),
        var_export($preloadHelpers, true),
        var_export(DiscoveryFixtures::directory().'/Support/helpers.php', true),
        var_export(DiscoveryFixtures::directory(), true),
    );

    $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script], $root);
    $process->run();

    /** @var array{classes?: list<string>, included?: list<string>} $output */
    $output = json_decode($process->getOutput(), true) ?? [];

    return [
        'exit' => (int) $process->getExitCode(),
        'error' => $process->getErrorOutput(),
        'classes' => $output['classes'] ?? [],
        'included' => $output['included'] ?? [],
    ];
}

/**
 * Run the finder over temporary class files in a fresh PHP process, with a Composer loader for their namespace, so a
 * fatal error while loading a class ends that process only.
 *
 * @return array{exit: int, error: string, classes: list<string>}
 */
function discoverTemporaryInSeparateProcess(TemporaryClasses $classes): array
{
    $root = dirname(__DIR__, 3);
    $script = sprintf(<<<'PHP'
        require %s;
        $loader = new Composer\Autoload\ClassLoader(%s);
        $loader->addPsr4(%s, %s);
        $loader->register();
        echo json_encode((new DF\HorizonObservability\Discovery\JobClassFinder)->find([%s]));
        PHP,
        var_export($root.'/vendor/autoload.php', true),
        var_export($classes->root.'/vendor', true),
        var_export($classes->namespace.'\\', true),
        var_export($classes->directory(), true),
        var_export($classes->directory(), true),
    );

    $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script], $root);
    $process->run();

    /** @var list<string>|null $found */
    $found = json_decode($process->getOutput(), true);

    return ['exit' => (int) $process->getExitCode(), 'error' => $process->getErrorOutput(), 'classes' => $found ?? []];
}

it('finds the queueable fixtures and nothing else', function (): void {
    expect((new JobClassFinder)->find([DiscoveryFixtures::directory()]))->toBe(DiscoveryFixtures::FOUND);
});

it('finds a class queued only through its parent', function (): void {
    expect(file_get_contents(DiscoveryFixtures::directory().'/Jobs/ImportChunk.php'))->not->toContain('ShouldQueue')
        ->and((new JobClassFinder)->find([DiscoveryFixtures::directory().'/Jobs']))->toContain(ImportChunk::class);
});

test('functions file never included', function (): void {
    $run = discoverInSeparateProcess(preloadHelpers: false);
    $fixture = static fn (string $path): string => (string) realpath(DiscoveryFixtures::directory().'/'.$path);

    expect($run['exit'])->toBe(0, $run['error'])
        ->and($run['included'])->not->toContain($fixture('Support/helpers.php'))
        ->and($run['included'])->not->toContain($fixture('Support/Misnamed.php'))
        ->and($run['included'])->not->toContain($fixture('Support/TwoClasses.php'))
        ->and($run['included'])->not->toContain($fixture('Support/WithFunction.php'))
        ->and($run['included'])->not->toContain($fixture('Support/WithConstant.php'))
        ->and($run['included'])->not->toContain($fixture('Jobs/SyncReport.php')) // neither extends nor implements
        ->and($run['included'])->toContain($fixture('Jobs/ProcessPodcast.php'))
        ->and($run['classes'])->toBe(DiscoveryFixtures::FOUND);
});

it('survives a functions file the application already loaded (no "Cannot redeclare")', function (): void {
    $run = discoverInSeparateProcess(preloadHelpers: true);

    expect($run['exit'])->toBe(0, $run['error'])
        ->and($run['error'])->not->toContain('Cannot redeclare')
        ->and($run['classes'])->toBe(DiscoveryFixtures::FOUND);
});

describe('temporary class files', function (): void {
    beforeEach(function (): void {
        $this->classes = TemporaryClasses::create();
    });

    afterEach(function (): void {
        $this->classes->remove();
    });

    it('returns sorted, unique classes for overlapping directories and ignores missing ones', function (): void {
        $beta = $this->classes->job('Beta');
        $alpha = $this->classes->job('Alpha');
        $directory = $this->classes->directory();

        expect((new JobClassFinder)->find([$directory, $directory.'/../psr4', $this->classes->root, $this->classes->root.'/missing']))
            ->toBe([$alpha, $beta]);
    });

    it('never includes a file that does not parse', function (): void {
        $good = $this->classes->job('Good');
        $this->classes->write('Broken', 'final class Broken implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { public function handle( }');

        expect((new JobClassFinder)->find([$this->classes->directory()]))->toBe([$good])
            ->and($this->classes->included('Broken'))->toBeFalse();
    });

    it('never loads a class that neither extends nor implements anything (no fatal error from code the app never loads)', function (): void {
        $good = $this->classes->job('Good');
        // Loading this class is an uncatchable "Trait not found" fatal error; it cannot be a ShouldQueue anyway.
        $this->classes->write('Standalone', "final class Standalone\n{\n    use \\Missing\\DevOnlyTrait;\n\n    public function handle(): void {}\n}");

        $run = discoverTemporaryInSeparateProcess($this->classes);

        expect($run['exit'])->toBe(0, $run['error'])
            ->and($run['error'])->not->toContain('not found')
            ->and($run['classes'])->toBe([$good]);
    });

    it('skips a file over the size limit without reading it', function (): void {
        $good = $this->classes->job('Good');
        $padding = str_repeat("// A generated file: no queueable class is this large.\n", intdiv(JobClassFinder::MAX_FILE_BYTES, 50));
        $this->classes->write('Huge', $padding."final class Huge implements \\Illuminate\\Contracts\\Queue\\ShouldQueue\n{\n    public function handle(): void {}\n}");

        expect(filesize($this->classes->directory().'/Huge.php'))->toBeGreaterThan(JobClassFinder::MAX_FILE_BYTES)
            ->and((new JobClassFinder)->find([$this->classes->directory()]))->toBe([$good])
            ->and($this->classes->included('Huge'))->toBeFalse();
    });

    it('skips a class whose parent is missing and keeps the others', function (): void {
        $good = $this->classes->job('Good');
        $this->classes->write('Orphan', "final class Orphan extends MissingParent implements \\Illuminate\\Contracts\\Queue\\ShouldQueue\n{\n    public function handle(): void {}\n}");

        expect((new JobClassFinder)->find([$this->classes->directory()]))->toBe([$good]);
    });

    it('does not load a class the autoloader maps to another file', function (): void {
        $scanned = $this->classes->root.'/scanned';
        $this->classes->write('Elsewhere', "final class Elsewhere implements \\Illuminate\\Contracts\\Queue\\ShouldQueue\n{\n    public function handle(): void {}\n}", $scanned);
        $this->classes->write('Elsewhere', "function elsewhere(): void {}\n\nfinal class Elsewhere {}");

        expect((new JobClassFinder)->find([$scanned]))->toBe([])
            ->and($this->classes->included('Elsewhere'))->toBeFalse()
            ->and($this->classes->included('Elsewhere', $scanned))->toBeFalse()
            ->and(class_exists($this->classes->namespace.'\\Elsewhere', false))->toBeFalse();
    });

    it('skips a class already loaded from another file', function (): void {
        $scanned = $this->classes->root.'/scanned';
        $class = $this->classes->job('Loaded');
        $this->classes->write('Loaded', "final class Loaded implements \\Illuminate\\Contracts\\Queue\\ShouldQueue\n{\n    public function handle(): void {}\n}", $scanned);

        expect(class_exists($class))->toBeTrue()
            ->and((new JobClassFinder)->find([$scanned]))->toBe([])
            ->and((new JobClassFinder)->find([$this->classes->directory()]))->toBe([$class]);
    });
});
