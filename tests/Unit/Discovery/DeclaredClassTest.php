<?php

declare(strict_types=1);

use DF\HorizonObservability\Discovery\JobClassFinder;

/*
| The token prefilter of the job class discovery: which files may be handed to the autoloader. A
| file passes only when it parses and declares exactly one class, named like the file, in at most one namespace,
| with no file-level function, constant, include or require.
*/

function declaredClassSource(string $body, string $namespace = 'App\\Jobs'): string
{
    $header = $namespace === '' ? '' : "namespace {$namespace};\n\n";

    return "<?php\n\ndeclare(strict_types=1);\n\n{$header}{$body}\n";
}

it('returns the class a PSR-4 file declares', function (string $body, string $namespace, string $expected): void {
    expect(JobClassFinder::declaredClass(declaredClassSource($body, $namespace), 'Foo'))->toBe($expected);
})->with([
    'namespaced class' => ['final class Foo implements \\Illuminate\\Contracts\\Queue\\ShouldQueue {}', 'App\\Jobs', 'App\\Jobs\\Foo'],
    'single-segment namespace' => ['class Foo {}', 'App', 'App\\Foo'],
    'global class' => ['class Foo {}', '', 'Foo'],
    'abstract class (reflection skips it later)' => ['abstract class Foo {}', 'App\\Jobs', 'App\\Jobs\\Foo'],
    'final readonly class with an attribute' => ["#[\\Attribute]\nfinal readonly class Foo {}", 'App\\Jobs', 'App\\Jobs\\Foo'],
    'imports, including group imports of functions and constants' => [
        "use Illuminate\\Support\\{Arr, Str};\nuse function strlen;\nuse const PHP_EOL;\nuse Foo\\{Bar, function baz, const QUX};\n\nclass Foo {}",
        'App\\Jobs',
        'App\\Jobs\\Foo',
    ],
    'a file-level closure with use' => ["\$x = 1;\n\$f = function () use (\$x) { return \$x; };\n\nclass Foo {}", 'App\\Jobs', 'App\\Jobs\\Foo'],
    'a file-level anonymous class with methods' => ["\$o = new class {\n    public function run(): void {}\n};\n\nclass Foo {}", 'App\\Jobs', 'App\\Jobs\\Foo'],
    'methods, nested functions, anonymous classes and ::class' => [
        <<<'PHP'
            class Foo
            {
                use \Illuminate\Bus\Queueable { onQueue as protected queueOn; }

                public const NAME = 'foo';

                public function handle(): void
                {
                    function nested(): void {}

                    $object = new class {
                        public function list(): array { return []; }
                    };
                    $name = self::class;
                    $text = "{$name} {$this->class()}";
                    $fn = fn (): int => 1;
                }

                public function class(): string { return 'x'; }

                public function enum(): string { return 'y'; }

                public function &reference(): array { static $a = []; return $a; }
            }
            PHP,
        'App\\Jobs',
        'App\\Jobs\\Foo',
    ],
    'a conditional class declaration' => ["if (! class_exists(Foo::class, false)) {\n    class Foo {}\n}", 'App\\Jobs', 'App\\Jobs\\Foo'],
    'a require inside a method' => ["class Foo\n{\n    public function handle(): void\n    {\n        require __DIR__.'/task.php';\n    }\n}", 'App\\Jobs', 'App\\Jobs\\Foo'],
]);

it('accepts a braced namespace', function (): void {
    $source = "<?php\n\nnamespace App\\Jobs {\n    class Foo {}\n}\n";

    expect(JobClassFinder::declaredClass($source, 'Foo'))->toBe('App\\Jobs\\Foo');
});

it('rejects files that must not be autoloaded', function (string $body): void {
    expect(JobClassFinder::declaredClass(declaredClassSource($body), 'Foo'))->toBeNull();
})->with([
    'no class at all (a functions file)' => ["use Illuminate\\Contracts\\Queue\\ShouldQueue;\n\nfunction foo(ShouldQueue \$job): bool { return true; }"],
    'a class named unlike the file' => ['class Bar {}'],
    'a class named like the file in another case' => ['class foo {}'],
    'two classes' => ["class Foo {}\nclass Bar {}"],
    'a class and an interface' => ["class Foo {}\ninterface Bar {}"],
    'an interface' => ['interface Foo {}'],
    'a trait' => ['trait Foo {}'],
    'an enum' => ['enum Foo: string { case A = \'a\'; }'],
    'a class next to a file-level function' => ["class Foo {}\nfunction helper(): void {}"],
    'a class next to a by-reference file-level function' => ["class Foo {}\nfunction &helper(): array { static \$a = []; return \$a; }"],
    'a class next to a guarded function' => ["class Foo {}\nif (! function_exists('helper')) {\n    function helper(): void {}\n}"],
    'a class next to a file-level constant' => ["const LIMIT = 5;\nclass Foo {}"],
    'a class next to a file-level require' => ["require __DIR__.'/helpers.php';\n\nclass Foo {}"],
    'a class next to a conditional include_once' => ["if (PHP_OS_FAMILY === 'Linux') {\n    include_once __DIR__.'/linux.php';\n}\n\nclass Foo {}"],
    'a syntax error' => ['class Foo { public function handle( }'],
    'inline HTML only' => ['?><p>Foo</p><?php'],
]);

it('rejects a file with two namespaces', function (): void {
    $source = "<?php\n\nnamespace App\\A;\n\nclass Foo {}\n\nnamespace App\\B;\n";

    expect(JobClassFinder::declaredClass($source, 'Foo'))->toBeNull()
        ->and(JobClassFinder::declaredClass('', 'Foo'))->toBeNull();
});
