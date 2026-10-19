<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\Events\JobPushed;
use Workbench\App\Discovery\Jobs\ImportChunk;
use Workbench\App\Discovery\Jobs\ProcessPodcast;
use Workbench\App\Discovery\Jobs\PruneUploads;

/*
| Exporter truth (T16): queue_job_classes lists the job_class values the application's queueable classes produce,
| checked against PHP's own reflection of the configured directories (workbench/app/Discovery and Simulation):
| - no false positive: every listed class exists, is instantiable, implements ShouldQueue, is what a queue pushes (a
|   job or listener with handle() or __invoke(), a mailable, a notification) and has no displayName(), so a dispatch
|   labels it with its class name (dispatched here for the jobs that take no arguments);
| - no false negative: every such class of a plain PSR-4 file (one class, no functions or constants, as the
|   discovery's safety rules require, C22) is listed.
*/

pest()->group('redis', 'truth');

beforeEach(function (): void {
    $this->bootstrap = sys_get_temp_dir().'/ho-truth-classes-'.getmypid().'-'.bin2hex(random_bytes(3));
    mkdir($this->bootstrap.'/cache', 0755, true);
    $this->app->useBootstrapPath($this->bootstrap);
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(JobClassCache::class);
});

afterEach(function (): void {
    Scenario::stop();
    (new Filesystem)->deleteDirectory($this->bootstrap);
});

/**
 * Queueable classes of plain PSR-4 files under a directory of `Workbench\App\`, by reflection.
 *
 * @return list<class-string>
 */
function jobClassesTruthReflected(string $directory): array
{
    $root = dirname(__DIR__, 2).'/workbench/app/';
    $classes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        if (! jobClassesTruthPlainFile((string) file_get_contents($file->getPathname()))) {
            continue; // functions or constants outside a class, or several classes: not a plain class file
        }

        $class = 'Workbench\\App\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));

        if (! class_exists($class)) {
            continue; // the file declares another name (PSR-4 mismatch)
        }

        $reflection = new ReflectionClass($class);

        // What a queue pushes: a job or listener (handle() or __invoke()), a mailable or a notification; an event
        // marked ShouldQueue is never pushed itself.
        $pushed = $reflection->hasMethod('handle') || $reflection->hasMethod('__invoke')
            || $reflection->isSubclassOf(Mailable::class) || $reflection->isSubclassOf(Notification::class);

        if ($reflection->isInstantiable() && $reflection->implementsInterface(ShouldQueue::class) && $pushed && ! $reflection->hasMethod('displayName')) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * Whether a PHP file declares exactly one class and nothing else at the top level (no function, no constant).
 */
function jobClassesTruthPlainFile(string $code): bool
{
    $depth = 0;
    $classes = 0;
    $previous = null;

    foreach (token_get_all($code) as $token) {
        $id = is_array($token) ? $token[0] : $token;

        if (in_array($id, ['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
        } elseif ($id === '}') {
            $depth--;
        } elseif ($depth === 0 && in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $previous !== T_DOUBLE_COLON) {
            $classes++;
        } elseif ($depth === 0 && ($id === T_CONST || ($id === T_FUNCTION && $previous !== T_USE))) {
            return false;
        }

        if (! in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $previous = $id;
        }
    }

    return $classes === 1;
}

it('lists exactly the queueable classes of the application code, by their job_class label', function (): void {
    $scenario = Scenario::start($this);
    $paths = (array) config('horizon-observability.job_class_paths');
    $expected = [];

    foreach ($paths as $path) {
        array_push($expected, ...jobClassesTruthReflected((string) $path));
    }

    sort($expected);
    $listed = array_map(static fn (array $labels): string => $labels['job_class'], (new Samples($scenario->scrape()))->labelSets(Metric::JobClasses->value));
    sort($listed);

    expect($paths)->not->toBe([])
        ->and($expected)->toContain(ProcessPodcast::class, ImportChunk::class, PruneUploads::class)
        ->and($listed)->toBe($expected);
});

it('lists the labels that dispatching those jobs produces', function (): void {
    $scenario = Scenario::start($this);
    $labels = [];
    Event::listen(JobPushed::class, static function (JobPushed $event) use (&$labels): void {
        $labels[] = $event->payload->decoded['displayName'];
    });

    foreach ([ProcessPodcast::class, ImportChunk::class, PruneUploads::class] as $class) {
        dispatch((new $class)->onConnection('redis'));
    }

    $listed = array_map(static fn (array $set): string => $set['job_class'], (new Samples($scenario->scrape()))->labelSets(Metric::JobClasses->value));

    expect($labels)->toBe([ProcessPodcast::class, ImportChunk::class, PruneUploads::class])
        ->and(array_diff($labels, $listed))->toBe([]);
});
