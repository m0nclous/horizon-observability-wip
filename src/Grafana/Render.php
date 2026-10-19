<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

/**
 * The files of one render (Arch Q5.1): the dashboard and the alert rules, as Grafana file provisioning reads them,
 * and with `--recording-rules` the Prometheus rule file, keyed by their path relative to the output directory.
 *
 * @internal
 */
final readonly class Render
{
    public const DASHBOARD = 'dashboards/horizon-observability.json';

    public const ALERTING = 'alerting/horizon-observability.json';

    public const RECORDING_RULES = 'prometheus/horizon-observability.rules.yml';

    public function __construct(private DashboardOptions $options) {}

    /**
     * @return array<string, string> relative path => contents
     */
    public function files(): array
    {
        $files = [
            self::DASHBOARD => Json::encode((new DashboardBuilder($this->options))->build()),
            self::ALERTING => Json::encode((new AlertRulesBuilder($this->options))->build()),
        ];

        if ($this->options->recordingRules) {
            $files[self::RECORDING_RULES] = (new RecordingRulesBuilder($this->options))->yaml();
        }

        return $files;
    }

    /**
     * Where $actual differs from the render $expected, for `--check`: the lines of the file between the common
     * first and last lines, and the first differing line of each side. Null when they are equal.
     */
    public static function difference(string $expected, string $actual): ?string
    {
        if ($expected === $actual) {
            return null;
        }

        $want = explode("\n", $expected);
        $have = explode("\n", $actual);
        $head = 0;

        while ($head < count($want) && $head < count($have) && $want[$head] === $have[$head]) {
            $head++;
        }

        $tail = 0;

        while ($tail < count($want) - $head && $tail < count($have) - $head
            && $want[count($want) - 1 - $tail] === $have[count($have) - 1 - $tail]) {
            $tail++;
        }

        $changed = count($have) - $head - $tail;
        $rendered = count($want) - $head - $tail;

        if ($changed === 0) {
            return sprintf('%s missing after line %d, first %s', self::lines($rendered, 'rendered line'), $head, self::excerpt($want[$head]));
        }

        if ($rendered === 0) {
            return sprintf('%s not in the render from line %d, first %s', self::lines($changed, 'line'), $head + 1, self::excerpt($have[$head]));
        }

        return sprintf(
            'lines %d-%d differ from %s; first difference at line %d: %s instead of %s',
            $head + 1,
            $head + $changed,
            self::lines($rendered, 'rendered line'),
            $head + 1,
            self::excerpt($have[$head]),
            self::excerpt($want[$head]),
        );
    }

    private static function lines(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }

    private static function excerpt(string $line): string
    {
        $line = trim($line);

        return '`'.(strlen($line) > 120 ? substr($line, 0, 117).'...' : $line).'`';
    }
}
