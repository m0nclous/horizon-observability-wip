<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Grafana\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs Prometheus rule unit tests (`promtool test rules`) built by a test: the rendered expressions evaluated on
 * known input series, so a test can prove what a panel or an alert rule computes, not only that it parses.
 *
 * promtool comes from PROMTOOL (e.g. "docker run --rm -i --entrypoint promtool quay.io/prometheus/prometheus:v3.15.0",
 * as in the CI grafana job; the test directory is then mounted into the container) or from the PATH.
 */
final class Promtool
{
    public static function command(): ?string
    {
        $promtool = getenv('PROMTOOL');

        return is_string($promtool) && $promtool !== '' ? $promtool : (new ExecutableFinder)->find('promtool');
    }

    /**
     * @param  list<array<string, mixed>>  $groups  the rule groups under test (may be empty for expression tests)
     * @param  list<array<string, mixed>>  $tests  the `tests` entries of the unit test file
     * @return array{0: int, 1: string} the exit code and the output of promtool
     */
    public static function testRules(array $groups, array $tests): array
    {
        $command = self::command() ?? throw new RuntimeException('promtool is not available.');
        $directory = sys_get_temp_dir().'/ho-promtool-'.bin2hex(random_bytes(6));
        mkdir($directory);

        try {
            file_put_contents($directory.'/rules.yml', Yaml::dump(['groups' => $groups], 12, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
            file_put_contents($directory.'/test.yml', Yaml::dump([
                'rule_files' => ['rules.yml'],
                'evaluation_interval' => '1m',
                'tests' => $tests,
            ], 12, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

            if (str_starts_with($command, 'docker run ')) {
                $command = 'docker run -v '.escapeshellarg($directory.':'.$directory.':ro').' '.substr($command, strlen('docker run '));
            }

            $result = Process::timeout(600)->run($command.' test rules '.escapeshellarg($directory.'/test.yml'));

            return [$result->exitCode() ?? 1, $result->output().$result->errorOutput()];
        } finally {
            @unlink($directory.'/rules.yml');
            @unlink($directory.'/test.yml');
            @rmdir($directory);
        }
    }

    /**
     * An expression as Prometheus receives it from the dashboard at eval time $to (seconds after the start of the
     * input series, with milliseconds) with a range of $range seconds and the scope $job: the period edges as Grafana
     * formats them (`${__to}` in milliseconds, `${__to:date:seconds}` as Math.round(ms / 1000)), the textbox defaults,
     * "All" for `${x:text}` and `.*` for every other variable (Grafana's All of the filters).
     */
    public static function interpolate(string $expr, string $job, int|float $to, int $range): string
    {
        $toMs = (int) round($to * 1000);
        $fromMs = $toMs - $range * 1000;
        $expr = strtr($expr, [
            '${__from:date:seconds}' => (string) self::roundHalfUp($fromMs / 1000),
            '${__to:date:seconds}' => (string) self::roundHalfUp($toMs / 1000),
            '${__from}' => (string) $fromMs,
            '${__to}' => (string) $toMs,
            '$__interval_ms' => '60000',
            '${__interval_ms}' => '60000',
            '$__interval' => '1m',
            '$__range' => $range.'s',
            '$busy_window' => '2m',
            '$scrape_interval' => '1m',
            '$prometheus_job' => $job,
        ]);
        $expr = (string) preg_replace('/\$\{[A-Za-z_]\w*:text\}/', 'All', $expr);

        // $1 in label_replace() replacements is not a variable
        return (string) preg_replace('/\$\{[A-Za-z_]\w*(?::\w+)?\}|\$[A-Za-z_]\w*/', '.*', $expr);
    }

    /**
     * JavaScript's Math.round: halves round up.
     */
    private static function roundHalfUp(float $value): int
    {
        return (int) floor($value + 0.5);
    }
}
