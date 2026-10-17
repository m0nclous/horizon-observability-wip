<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Integration\Discovery;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;

/**
 * Class files written at test time into a temporary directory and autoloaded by a real Composer loader (PSR-4, its
 * own namespace per instance), for discovery cases the workbench fixtures cannot hold: files added between two
 * scrapes, a syntax error, a missing parent class, a name the autoloader maps to another file.
 */
final class TemporaryClasses
{
    private static int $sequence = 0;

    /**
     * Root of this instance's files; PSR-4 maps {@see self::$namespace} to its `psr4` subdirectory.
     */
    public readonly string $root;

    public readonly string $namespace;

    private readonly ClassLoader $loader;

    private function __construct()
    {
        $id = getmypid().'_'.(++self::$sequence).'_'.bin2hex(random_bytes(3));
        $this->root = sys_get_temp_dir().'/ho-discovery-'.$id;
        $this->namespace = 'HoDiscoveryTemp\\Run'.$id;

        mkdir($this->root.'/psr4', 0755, true);

        // A vendor directory of its own registers the loader in ClassLoader::getRegisteredLoaders().
        $this->loader = new ClassLoader($this->root.'/vendor');
        $this->loader->addPsr4($this->namespace.'\\', $this->root.'/psr4');
        $this->loader->register();
    }

    public static function create(): self
    {
        return new self;
    }

    /**
     * The PSR-4 directory, which tests scan.
     */
    public function directory(): string
    {
        return $this->root.'/psr4';
    }

    /**
     * A queued job `{namespace}\{name}` with handle(); its class name.
     */
    public function job(string $name): string
    {
        return $this->write($name, <<<PHP
            final class {$name} implements \\Illuminate\\Contracts\\Queue\\ShouldQueue
            {
                public function handle(): void {}
            }
            PHP);
    }

    /**
     * Write `{directory}/{name}.php` with the namespace header and the given body; the class name it would declare.
     */
    public function write(string $name, string $body, ?string $directory = null): string
    {
        $directory ??= $this->directory();

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($directory.'/'.$name.'.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$this->namespace};\n\n{$body}\n");

        return $this->namespace.'\\'.$name;
    }

    /**
     * Whether this process has included the file `{directory}/{name}.php`.
     */
    public function included(string $name, ?string $directory = null): bool
    {
        $path = realpath(($directory ?? $this->directory()).'/'.$name.'.php');

        return $path !== false && in_array($path, get_included_files(), true);
    }

    public function remove(): void
    {
        $this->loader->unregister();
        (new Filesystem)->deleteDirectory($this->root);
    }
}
