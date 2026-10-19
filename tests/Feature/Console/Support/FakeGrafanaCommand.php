<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Feature\Console\Support;

use Illuminate\Console\Command;

/**
 * Stand-in for `horizon-observability:grafana` (T13) while its class is not on this branch: the same arguments and
 * output files (`{path}/dashboards/horizon-observability.json`, `{path}/alerting/horizon-observability.json`) and
 * `--check` semantics, so the install tests run before and after the merge. Registered only when the real command
 * is missing.
 */
final class FakeGrafanaCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'horizon-observability:grafana
                            {path=grafana}
                            {--check}
                            {--job=}
                            {--datasource-uid=}
                            {--live=poll}';

    /**
     * @var list<array<string, mixed>>
     */
    public static array $calls = [];

    public function handle(): int
    {
        self::$calls[] = ['path' => $this->argument('path'), ...$this->options()];
        $path = (string) $this->argument('path');
        $files = [
            'dashboards/horizon-observability.json' => json_encode(['uid' => 'horizon-observability', 'title' => 'Queues', 'job' => $this->option('job')], JSON_PRETTY_PRINT)."\n",
            'alerting/horizon-observability.json' => json_encode(['apiVersion' => 1, 'groups' => [['name' => 'Queues', 'datasourceUid' => $this->option('datasource-uid')]]], JSON_PRETTY_PRINT)."\n",
        ];

        foreach ($files as $file => $contents) {
            $target = $path.'/'.$file;

            if ($this->option('check') === true) {
                if (! is_file($target) || file_get_contents($target) !== $contents) {
                    return self::FAILURE;
                }

                continue;
            }

            @mkdir(dirname($target), 0777, true);
            file_put_contents($target, $contents);
        }

        return self::SUCCESS;
    }
}
