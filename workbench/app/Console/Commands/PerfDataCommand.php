<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Workbench\App\Perf\SyntheticModel;

/**
 * Writes the synthetic history of the performance profile as OpenMetrics chunks for
 * `promtool tsdb create-blocks-from openmetrics`. One chunk per 2-hour block window, aligned
 * like Prometheus blocks, plus `info.om` with 7 days of `horizon_info` at 60 s (the dashboard reads it with a 7-day
 * lookback).
 *
 * Samples carry `job` and `instance` exactly as the perf Prometheus adds them to the continuation exporter's series
 * (docker/perf/prometheus.yml), so the backfill and the live scrape form one series each.
 *
 * A 26-hour medium history is about 4 GB of text at 10 s; `--list` and `--window` let docker/perf/backfill.sh write,
 * convert and delete one chunk at a time.
 */
final class PerfDataCommand extends Command
{
    public const WINDOW_SECONDS = 7200;

    public const INFO = 'info';

    protected $signature = 'workbench:perf-data
        {--profile=medium : medium (about 5,300 series) or large}
        {--schema=catalog : catalog (the package metric names) or legacy (the pilot names the legacy dashboard reads)}
        {--namespace= : Metric prefix without "_"; default ho (catalog) or office (legacy)}
        {--hours=26 : Hours of history}
        {--step=10 : Seconds between samples (the scrape interval S)}
        {--end= : Unix time the history ends at; default now. Pass the same value to every --window call}
        {--out=build/perf/om : Output directory, relative to the current directory}
        {--job=perf-horizon : The job label (the perf Prometheus job name)}
        {--instance=perf-exporter : The instance label (relabelled in docker/perf/prometheus.yml)}
        {--list : Print the chunk names (window start times and "info") instead of writing}
        {--window= : Write only one chunk: a window start time from --list, or "info"}';

    protected $description = 'Write the synthetic perf-profile history as OpenMetrics chunks (development only)';

    public function handle(): int
    {
        try {
            $model = new SyntheticModel($this->text('profile', 'medium'), $this->text('schema', SyntheticModel::CATALOG), $this->nullableText('namespace'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $step = max(1, $this->number('step', 10));
        $end = $this->number('end', time());
        $start = $end - (int) round($this->floatNumber('hours', 26.0) * 3600);
        $start -= $start % $step;
        $windows = [];

        for ($window = $start - $start % self::WINDOW_SECONDS; $window < $end; $window += self::WINDOW_SECONDS) {
            $windows[] = $window;
        }

        if ($this->option('list') === true) {
            foreach ($windows as $window) {
                $this->line((string) $window);
            }

            $this->line(self::INFO);

            return self::SUCCESS;
        }

        $out = $this->text('out', 'build/perf/om');
        $out = str_starts_with($out, '/') ? $out : getcwd().'/'.$out;

        if (! is_dir($out) && ! mkdir($out, 0777, true) && ! is_dir($out)) {
            $this->error('Cannot create '.$out);

            return self::FAILURE;
        }

        $extra = sprintf('job="%s",instance="%s"', addcslashes($this->text('job', 'perf-horizon'), '\\"'), addcslashes($this->text('instance', 'perf-exporter'), '\\"'));
        $only = $this->nullableText('window');

        if ($only !== null && $only !== self::INFO && ! in_array((int) $only, $windows, true)) {
            $this->error(sprintf('Unknown window %s; see --list.', $only));

            return self::FAILURE;
        }

        foreach ($windows as $window) {
            if ($only === null || $only === (string) $window) {
                $path = $this->chunk($model, $out, $window, max($start, $window), min($end, $window + self::WINDOW_SECONDS), $step, $extra);
                $this->line($path);
            }
        }

        if ($only === null || $only === self::INFO) {
            $this->line($this->infoChunk($model, $out, $end, $extra));
        }

        return self::SUCCESS;
    }

    private function chunk(SyntheticModel $model, string $out, int $window, int $from, int $to, int $step, string $extra): string
    {
        $path = sprintf('%s/chunk-%d.om', $out, $window);
        $file = fopen($path, 'wb');

        if ($file === false) {
            throw new InvalidArgumentException('Cannot write '.$path);
        }

        $t = $from % $step === 0 ? $from : $from - $from % $step + $step;

        for (; $t < $to; $t += $step) {
            fwrite($file, $model->samples($t, $extra));
        }

        fwrite($file, "# EOF\n");
        fclose($file);

        return $path;
    }

    private function infoChunk(SyntheticModel $model, string $out, int $end, string $extra): string
    {
        $path = $out.'/info.om';
        $file = fopen($path, 'wb');

        if ($file === false) {
            throw new InvalidArgumentException('Cannot write '.$path);
        }

        $t = $end - 7 * 86400;

        for ($t -= $t % 60; $t < $end; $t += 60) {
            fwrite($file, $model->horizonInfo($t, $extra));
        }

        fwrite($file, "# EOF\n");
        fclose($file);

        return $path;
    }

    private function text(string $name, string $default): string
    {
        return $this->nullableText($name) ?? $default;
    }

    private function nullableText(string $name): ?string
    {
        $value = $this->option($name);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function number(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function floatNumber(string $name, float $default): float
    {
        $value = $this->option($name);

        return is_numeric($value) ? (float) $value : $default;
    }
}
