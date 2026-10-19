<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Discovery;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use PhpToken;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Queueable classes in the given directories: token prefilter plus reflection, never including files.
 *
 * 1. Every `*.php` file below the directories is a candidate (dot directories and VCS folders are skipped, symlinked
 *    directories are not followed). Files over {@see self::MAX_FILE_BYTES} are skipped unread: tokenizing costs
 *    about 25 to 75 bytes of memory per source byte, and no queueable class is that large.
 * 2. **Token prefilter** ({@see self::declaredClass()}): the file must parse, declare exactly one class-like (and that
 *    one a class) named like the file (PSR-4), at most one namespace, and no file-level function, constant,
 *    `include` or `require`: any other file (a helpers file loaded through Composer's `files`, for one) is never
 *    handed to the autoloader, so it can never be included a second time ("Cannot redeclare"). A class that neither
 *    extends nor implements anything cannot be a `ShouldQueue` (traits add no interfaces), so it is not loaded
 *    either: loading application code the application itself may never load (a class using a trait of a dev-only
 *    package, say) can end the process with an uncatchable fatal error, so discovery loads as little as it can.
 * 3. **Autoload**: when a registered Composer loader maps the class name to another file, the class is skipped
 *    without loading anything; otherwise `class_exists()` loads it (the package never `include`s a file itself), and
 *    the class must come from the scanned file.
 * 4. **Reflection** keeps classes that implement `ShouldQueue` (inherited included), are not abstract, have no
 *    `displayName()` (declared or inherited: the payload `displayName`, hence the `job_class` label, would not be
 *    the class name), and have `handle()` or `__invoke()` (Laravel's bus and queued listeners call `__invoke()`
 *    when there is no `handle()`) or are a `Notification` or a `Mailable`.
 *
 * Any failure of a file (unreadable, a missing parent class, ...) skips that file only. The result is sorted.
 *
 * @internal
 */
final class JobClassFinder
{
    /**
     * Kinds of brace scopes, for the file-level checks of the prefilter.
     */
    private const SCOPE_NAMESPACE = 'namespace';

    private const SCOPE_CLASS = 'class';

    private const SCOPE_FUNCTION = 'function';

    private const SCOPE_OTHER = 'other';

    /**
     * Larger files are not read: the token prefilter needs memory proportional to the file size.
     */
    public const MAX_FILE_BYTES = 524288;

    /**
     * @param  list<string>  $paths
     * @return list<string> sorted class names
     */
    public function find(array $paths): array
    {
        $classes = [];

        foreach ($this->files($paths) as $file) {
            $class = $this->candidate($file);

            if ($class !== null && $this->isQueueable($class, $file)) {
                $classes[$class] = true;
            }
        }

        $classes = array_map(strval(...), array_keys($classes));
        sort($classes, SORT_STRING);

        return $classes;
    }

    /**
     * The token prefilter: the fully qualified class a PSR-4 file declares, or null when the file must not be
     * autoloaded (it does not parse, declares no class or several class-likes, an interface, trait or enum, a class
     * named unlike the file, several namespaces, or a file-level function, constant, `include` or `require`).
     */
    public static function declaredClass(string $source, string $basename): ?string
    {
        return self::declaration($source, $basename)['class'] ?? null;
    }

    /**
     * {@see self::declaredClass()}, plus whether the class extends or implements anything.
     *
     * @return array{class: string, inherits: bool}|null
     */
    private static function declaration(string $source, string $basename): ?array
    {
        if ($basename === '' || ! str_contains($source, $basename) || stripos($source, 'class') === false) {
            return null; // it cannot declare a class named like the file: not worth tokenizing
        }

        try {
            $tokens = PhpToken::tokenize($source, TOKEN_PARSE);
        } catch (Throwable) {
            return null; // ParseError: the file must never be loaded
        }

        $namespace = '';
        $namespaces = 0;
        /** @var list<array{0: int, 1: string, 2: bool}> $declarations kind token id, name, extends or implements */
        $declarations = [];
        /** @var list<string> $scopes */
        $scopes = [];
        $pending = null;
        $previous = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->isIgnorable()) {
                continue;
            }

            $fileLevel = ! in_array(self::SCOPE_CLASS, $scopes, true) && ! in_array(self::SCOPE_FUNCTION, $scopes, true);

            if ($token->is(T_NAMESPACE)) {
                $namespaces++;
                $next = self::next($tokens, $i);

                if ($next !== null && $tokens[$next]->is([T_STRING, T_NAME_QUALIFIED])) {
                    $namespace = $tokens[$next]->text;
                }

                $pending = self::SCOPE_NAMESPACE;
            } elseif ($token->is(T_USE) && $fileLevel && $previous?->text !== ')') {
                // An import (possibly a group with `function` and `const` entries): skip to its end.
                while ($i + 1 < $count && $tokens[$i + 1]->text !== ';') {
                    $i++;
                }
            } elseif ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                $next = self::next($tokens, $i);

                if ($next !== null && $tokens[$next]->is(T_STRING)) {
                    $declarations[] = [$token->id, $tokens[$next]->text, self::inherits($tokens, $next)];
                }

                $pending = self::SCOPE_CLASS; // named or anonymous: the next brace opens its body
            } elseif ($token->is(T_FUNCTION)) {
                $next = self::next($tokens, $i);

                if ($next !== null && $tokens[$next]->text === '&') {
                    $next = self::next($tokens, $next); // by-reference return
                }

                if ($fileLevel && $next !== null && $tokens[$next]->is(T_STRING)) {
                    return null; // a file-level function: a helpers file
                }

                $pending = self::SCOPE_FUNCTION;
            } elseif ($token->is([T_CONST, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE]) && $fileLevel) {
                return null; // a file-level constant, or an include that could load a functions file again
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $scopes[] = $token->text === '{' ? ($pending ?? self::SCOPE_OTHER) : self::SCOPE_OTHER;
                $pending = null;
            } elseif ($token->text === '}') {
                array_pop($scopes);
            } elseif ($token->text === ';') {
                $pending = null;
            }

            $previous = $token;
        }

        if ($namespaces > 1 || count($declarations) !== 1 || $declarations[0][0] !== T_CLASS || $declarations[0][1] !== $basename) {
            return null;
        }

        return [
            'class' => $namespace === '' ? $declarations[0][1] : $namespace.'\\'.$declarations[0][1],
            'inherits' => $declarations[0][2],
        ];
    }

    /**
     * Whether the declaration whose name is at position $i has an `extends` or `implements` clause before its body.
     *
     * @param  array<int, PhpToken>  $tokens  as {@see PhpToken::tokenize()} returns them (a list)
     */
    private static function inherits(array $tokens, int $i): bool
    {
        for ($j = $i + 1, $count = count($tokens); $j < $count && $tokens[$j]->text !== '{'; $j++) {
            if ($tokens[$j]->is([T_EXTENDS, T_IMPLEMENTS])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Position of the next significant token after position $i, or null.
     *
     * @param  array<int, PhpToken>  $tokens  as {@see PhpToken::tokenize()} returns them (a list)
     */
    private static function next(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
            if (! $tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /**
     * Real paths of the `*.php` files below the directories, sorted and unique.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function files(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            try {
                foreach (Finder::create()->files()->in($path)->name('*.php')->ignoreUnreadableDirs() as $file) {
                    $real = $file->getRealPath();

                    if (is_string($real)) {
                        $files[$real] = true;
                    }
                }
            } catch (Throwable) {
                continue; // a directory that vanished or cannot be listed
            }
        }

        $files = array_map(strval(...), array_keys($files));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * The class the file declares, if it passes the token prefilter and could implement `ShouldQueue`.
     */
    private function candidate(string $file): ?string
    {
        try {
            $size = is_file($file) && is_readable($file) ? @filesize($file) : false;
            $source = is_int($size) && $size <= self::MAX_FILE_BYTES ? @file_get_contents($file) : false;
        } catch (Throwable) {
            return null;
        }

        $declaration = is_string($source) ? self::declaration($source, basename($file, '.php')) : null;

        return $declaration !== null && $declaration['inherits'] ? $declaration['class'] : null;
    }

    private function isQueueable(string $class, string $file): bool
    {
        try {
            if ($this->resolvesElsewhere($class, $file) || ! class_exists($class)) {
                return false;
            }

            $reflection = new ReflectionClass($class);
            $declaredIn = $reflection->getFileName();

            return is_string($declaredIn)
                && realpath($declaredIn) === $file
                && $reflection->implementsInterface(ShouldQueue::class)
                && ! $reflection->isAbstract()
                && ! $reflection->isEnum()
                && ! $reflection->hasMethod('displayName')
                && ($reflection->hasMethod('handle')
                    || $reflection->hasMethod('__invoke')
                    || $reflection->isSubclassOf(Notification::class)
                    || $reflection->isSubclassOf(Mailable::class));
        } catch (Throwable) {
            return false; // e.g. a missing parent class or interface
        }
    }

    /**
     * Whether a registered Composer loader would load the class from a file other than the scanned one; that file has
     * not passed the prefilter, so the class is not loaded at all.
     */
    private function resolvesElsewhere(string $class, string $file): bool
    {
        if (class_exists($class, false)) {
            return false; // already loaded; the file check below decides
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $found = $loader->findFile($class);

            if (is_string($found)) {
                return realpath($found) !== $file;
            }
        }

        return false;
    }
}
