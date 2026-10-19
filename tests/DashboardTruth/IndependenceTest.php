<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\CaseContext;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\CaseDef;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Category;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\DomSpec;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Durations;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Expectation;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Grid;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Level;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Manifest;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\PanelSpec;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\TargetSpec;

/*
| The independence guards of the dashboard truth suite (T17, design sections 5.2 and 7.1), group grafana: the
| expectations restate documented semantics and never the dashboard's PromQL, so a spec cannot copy a defect it is
| meant to find.
|
| - Expect/** refers to no class of the package or the suite outside an allow-list: Metrics\Metric (names and
|   labels), the suite's Expect/** and the contracts that carry no query (not Contracts\Observation, the captured page
|   with every sent query). So DF\HorizonObservability\Grafana\* (the renderer and its queries), Live\Captions,
|   Cases\Renders and Cases\Plan (the render JSON), Compare\* (the captured frames and executed queries) and
|   tests/Grafana are out. Names are resolved as PHP resolves them: single, aliased and group imports, namespace
|   imports (`use DF\HorizonObservability\Grafana;` then `Grafana\Promql`), qualified, fully qualified and
|   namespace-relative names; a string literal naming a class of the package counts too.
| - Expect/** reads no file (an expectation is a function of the case context only): no file functions, file
|   iterators, include or require.
| - Expect/** holds no PromQL in its string literals (functions, aggregations with by/without, range selectors,
|   offsets, selectors with label matchers, binary operator modifiers).
| - No file of the suite uses tests/Grafana/Support/Promtool, whose interpolate() maps ${x:text} to All and every
|   variable to .* (the refuted round-3 probe).
|
| The self-test runs the same guard over planted files, one per way around it.
*/

pest()->group('grafana');

/**
 * The names Expect/** may refer to: exact names, or prefixes ending in a backslash.
 */
const DASHBOARD_TRUTH_EXPECT_ALLOWED = [
    Metric::class,
    'DF\HorizonObservability\Tests\DashboardTruth\Expect\\',
    CaseContext::class,
    CaseDef::class,
    Category::class,
    DomSpec::class,
    Durations::class,
    Expectation::class,
    Grid::class,
    Level::class,
    Manifest::class,
    Mode::class,
    PanelSpec::class,
    TargetSpec::class,
];

/**
 * Functions and classes that read files.
 */
const DASHBOARD_TRUTH_FILE_READERS = [
    'file_get_contents', 'file', 'fopen', 'readfile', 'parse_ini_file', 'glob', 'scandir', 'opendir', 'dir',
    'simplexml_load_file', 'SplFileObject', 'SplFileInfo', 'DirectoryIterator', 'RecursiveDirectoryIterator',
    'FilesystemIterator', 'GlobIterator',
];

/**
 * PromQL in a string literal.
 */
const DASHBOARD_TRUTH_PROMQL = [
    'a PromQL function call' => '/\b(?:sum|min|max|avg|count|rate|irate|increase|delta|idelta|deriv|resets|changes|last_over_time|min_over_time|max_over_time|avg_over_time|sum_over_time|count_over_time|histogram_quantile|clamp_min|clamp_max|label_replace|label_join|count_values|topk|bottomk|absent|vector|timestamp|group)\s*\(/',
    'an aggregation clause' => '/\b(?:by|without)\s*\(/',
    'a range selector or subquery' => '/\[\s*(?:\d+[smhdwy]|\$__\w+)(?::[^\]]*)?\]/',
    'an offset or @ modifier' => '/\boffset\s+-?\d+[smhd]|\s@\s*(?:\d|start\(|end\(|\$)/',
    'a selector with a label matcher' => '/\{[^{}]*\b[a-zA-Z_][a-zA-Z0-9_]*\s*(?:=~|!~|!=|=)\s*["\'\\\\]/',
    'a binary operator modifier' => '/\bon\s*\(|\bignoring\s*\(|\bgroup_(?:left|right)\b/',
];

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
 * The tokens of a PHP file without whitespace and comments.
 *
 * @return list<array{0: int, 1: string, 2: int}|string>
 */
function dashboardTruthTokens(string $file): array
{
    return array_values(array_filter(
        token_get_all((string) file_get_contents($file)),
        static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
}

/**
 * The fully qualified class, function and namespace names a PHP file refers to, resolved like PHP resolves them:
 * every import (single, aliased, group, function and const imports), every qualified name through the imports or
 * the file's namespace, and every fully qualified and namespace-relative name.
 *
 * @return list<array{name: string, line: int}>
 */
function dashboardTruthNames(string $file): array
{
    $tokens = dashboardTruthTokens($file);
    $namespace = '';
    $aliases = [];
    $names = [];
    $depth = 0;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token)) {
            $depth += $token === '{' ? 1 : ($token === '}' ? -1 : 0);

            continue;
        }

        if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;

            continue;
        }

        if ($token[0] === T_NAMESPACE && is_array($tokens[$i + 1] ?? null) && in_array($tokens[$i + 1][0], [T_STRING, T_NAME_QUALIFIED], true)) {
            $namespace = $tokens[++$i][1];

            continue;
        }

        // An import is a `use` at the top level; a closure's `use (...)` follows its parameter list
        if ($token[0] === T_USE && $depth === 0 && ($tokens[$i - 1] ?? null) !== ')') {
            // use [function|const] A\B [as C], D; and use A\{B [as C], D\E};
            $prefix = '';
            $current = null;

            for ($i++; $i < $count && $tokens[$i] !== ';'; $i++) {
                $part = $tokens[$i];

                if (! is_array($part) || in_array($part[0], [T_FUNCTION, T_CONST], true)) {
                    continue;
                }

                if (in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $current = ltrim(($prefix === '' ? '' : $prefix.'\\').ltrim($part[1], '\\'), '\\');
                    $names[] = ['name' => $current, 'line' => $part[2]];
                    $aliases[substr($current, (int) strrpos('\\'.$current, '\\'))] = $current;
                } elseif ($part[0] === T_AS && is_array($tokens[$i + 1] ?? null) && $current !== null) {
                    $aliases[$tokens[++$i][1]] = $current;
                } elseif ($part[0] === T_NS_SEPARATOR && ($tokens[$i + 1] ?? null) === '{' && $current !== null) {
                    // A group import: the name before `\{` is the prefix, not an import
                    array_pop($names);
                    unset($aliases[substr($current, (int) strrpos('\\'.$current, '\\'))]);
                    $prefix = $current;
                    $i++;
                }
            }

            continue;
        }

        $name = match ($token[0]) {
            T_NAME_FULLY_QUALIFIED => ltrim($token[1], '\\'),
            T_NAME_RELATIVE => ltrim($namespace.'\\'.substr($token[1], strlen('namespace\\')), '\\'),
            T_NAME_QUALIFIED => isset($aliases[strstr($token[1], '\\', true)])
                ? $aliases[strstr($token[1], '\\', true)].strstr($token[1], '\\')
                : ltrim($namespace.'\\'.$token[1], '\\'),
            default => null,
        };

        if ($name !== null) {
            $names[] = ['name' => $name, 'line' => $token[2]];
        }
    }

    return $names;
}

function dashboardTruthAllowed(string $name): bool
{
    foreach (DASHBOARD_TRUTH_EXPECT_ALLOWED as $allowed) {
        if ($name === $allowed || (str_ends_with($allowed, '\\') && str_starts_with($name, $allowed))) {
            return true;
        }
    }

    return false;
}

/**
 * Every violation of the Expect/** guards in the given files, as `<file>:<line>: <what>`.
 *
 * @param  list<string>  $files
 * @return list<string>
 */
function dashboardTruthIndependence(array $files): array
{
    $offending = [];

    foreach ($files as $file) {
        $where = static fn (int $line): string => basename($file).':'.$line.': ';

        foreach (dashboardTruthNames($file) as ['name' => $name, 'line' => $line]) {
            if (str_starts_with($name, 'DF\\') && ! dashboardTruthAllowed($name)) {
                $offending[] = $where($line).'refers to '.$name.' (not on the allow-list of Expect/**)';
            }
        }

        $tokens = dashboardTruthTokens($file);

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                $offending[] = $where($token[2]).'reads a file ('.$token[1].')';
            }

            $previous = $tokens[$index - 1] ?? null;
            $member = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true);

            if (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) && ! $member && in_array(ltrim($token[1], '\\'), DASHBOARD_TRUTH_FILE_READERS, true)) {
                $offending[] = $where($token[2]).'reads a file ('.ltrim($token[1], '\\').')';
            }

            if (! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            if (preg_match('/HorizonObservability\\\\/', $token[1]) === 1) {
                $offending[] = $where($token[2]).'names a class of the package in a string: '.$token[1];
            }

            foreach (DASHBOARD_TRUTH_PROMQL as $what => $pattern) {
                if (preg_match($pattern, $token[1]) === 1) {
                    $offending[] = $where($token[2]).$what.' in '.$token[1];
                }
            }
        }
    }

    return $offending;
}

/**
 * The guard's findings for one planted file in the Expect/Core namespace.
 *
 * @return list<string>
 */
function dashboardTruthPlanted(string $source): array
{
    $directory = sys_get_temp_dir().'/ho-t17-independence-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $probe = $directory.'/Probe.php';
    file_put_contents($probe, str_starts_with($source, '<?php') ? $source : "<?php\n\ndeclare(strict_types=1);\n\nnamespace DF\\HorizonObservability\\Tests\\DashboardTruth\\Expect\\Core;\n\n".$source."\n");

    try {
        return dashboardTruthIndependence([$probe]);
    } finally {
        unlink($probe);
        rmdir($directory);
    }
}

it('keeps the expectation engine away from the renderer, the captured queries, PromQL and files', function (): void {
    $files = dashboardTruthFiles(__DIR__.'/Expect');

    expect($files)->not->toBeEmpty()
        ->and(dashboardTruthIndependence($files))->toBe([]);
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

it('detects what it guards against', function (string $source, string $finding): void {
    expect(implode("\n", dashboardTruthPlanted($source)))->toContain($finding);
})->with([
    'an import of the renderer' => ['use DF\HorizonObservability\Grafana\Promql;', 'refers to DF\HorizonObservability\Grafana\Promql'],
    'a namespace import' => ["use DF\\HorizonObservability\\Grafana;\n\n\$x = Grafana\\Promql::class;", 'refers to DF\HorizonObservability\Grafana\Promql'],
    'an aliased namespace import' => ["use DF\\HorizonObservability as HO;\n\n\$x = HO\\Grafana\\Tiles::class;", 'refers to DF\HorizonObservability\Grafana\Tiles'],
    'a group import' => ['use DF\HorizonObservability\Grafana\{Promql, Tiles};', 'refers to DF\HorizonObservability\Grafana\Tiles'],
    'a fully qualified name' => ['$x = \DF\HorizonObservability\Live\Captions::class;', 'refers to DF\HorizonObservability\Live\Captions'],
    'a function import' => ['use function DF\HorizonObservability\Grafana\render;', 'refers to DF\HorizonObservability\Grafana\render'],
    'the render JSON' => ['$x = \DF\HorizonObservability\Tests\DashboardTruth\Cases\Renders::class;', 'refers to DF\HorizonObservability\Tests\DashboardTruth\Cases\Renders'],
    'the captured frames' => ['use DF\HorizonObservability\Tests\DashboardTruth\Compare\Observation;', 'refers to DF\HorizonObservability\Tests\DashboardTruth\Compare\Observation'],
    'the observation contract' => ['use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Observation;', 'refers to DF\HorizonObservability\Tests\DashboardTruth\Contracts\Observation'],
    'the Grafana tests' => ['use DF\HorizonObservability\Tests\Grafana\Support\Promtool;', 'refers to DF\HorizonObservability\Tests\Grafana\Support\Promtool'],
    'a class name in a string' => ['$x = \'DF\\\\HorizonObservability\\\\Grafana\\\\Promql\';', 'names a class of the package in a string'],
    'a file read' => ['$x = file_get_contents(\'resources/grafana/horizon-observability.json\');', 'reads a file (file_get_contents)'],
    'a file iterator' => ['$x = new \SplFileObject(\'x\');', 'reads a file (SplFileObject)'],
    'a require' => ['require \'x.php\';', 'reads a file (require)'],
    'an aggregation' => ['$x = \'sum by (queue) (x)\';', 'an aggregation clause'],
    'a range selector' => ['$x = \'x[10m]\';', 'a range selector or subquery'],
    'a selector with an exact matcher' => ['$x = \'ho_queue_jobs_total{status="completed"}\';', 'a selector with a label matcher'],
    'a selector with a regex matcher' => ['$x = "ho_queue_jobs_total{status=~\\"completed\\"}";', 'a selector with a label matcher'],
]);

it('allows what the expectation engine needs', function (): void {
    expect(dashboardTruthPlanted(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace DF\HorizonObservability\Tests\DashboardTruth\Expect\Panels;

        use DF\HorizonObservability\Metrics\Metric;
        use DF\HorizonObservability\Tests\DashboardTruth\Contracts\{CaseContext, Grid};
        use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\Counters;
        use LogicException;

        final class Probe
        {
            public function run(CaseContext $context, Grid $grid): string
            {
                $counters = new Counters([], Counters::lookbackMs($context->scrapeInterval()));

                return Metric::JobsTotal->value.sprintf('%d points on %s', count($grid->times), $grid->step()).$this->file().$counters::class.LogicException::class.Core\Format::class;
            }

            private function file(): string
            {
                return 'the {%s} series';
            }
        }
        PHP))->toBe([]);
});
