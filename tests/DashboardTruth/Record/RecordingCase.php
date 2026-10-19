<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Record;

use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use LogicException;

/**
 * The test case of the record stage (design section 6.2), applied with `uses()` in RecordTest.
 *
 * With DASHBOARD_TRUTH_RECORD=1 it points REDIS_PREFIX and HORIZON_PREFIX at the dataset's kept world
 * `ho_t17_<run>_<dataset>:` before the application boots (not at the per-process `hot_<pid>:` of {@see TestCase},
 * whose tearDown would delete it), and the database, batches included, at the world's SQLite file, because batches
 * must outlive the test (the `testing` database is in memory). The dataset is the test's data set value. Without
 * DASHBOARD_TRUTH_RECORD it is a plain {@see TestCase}.
 *
 * Horizon's job records expire at `now + horizon.trim.*` (EXPIREAT): on the virtual 2025 clock that is already past,
 * so Redis would delete every record at once. The world keeps them by trimming after ten years; TtlFreeze persists
 * them at the end.
 *
 * It also keeps the raw body of the last metrics scrape ({@see self::lastScrape()}): T16's `Scenario::scrape()` parses
 * the exposition, the recording keeps it as served.
 */
abstract class RecordingCase extends TestCase
{
    public const CONNECTION = 'dashboard_truth';

    private const TRIM_MINUTES = 10 * 365 * 24 * 60;

    private ?string $lastScrape = null;

    public static function recording(): bool
    {
        return getenv('DASHBOARD_TRUTH_RECORD') === '1';
    }

    /**
     * The run id of the record stage (DASHBOARD_TRUTH_RUN).
     */
    public static function runId(): string
    {
        $run = getenv('DASHBOARD_TRUTH_RUN');

        if (! is_string($run) || $run === '') {
            throw new LogicException('DASHBOARD_TRUTH_RUN is not set (bin/run sets it).');
        }

        return $run;
    }

    /**
     * The run directory (DASHBOARD_TRUTH_RUN_DIR), `build/dashboard-truth/<run>`.
     */
    public static function runDir(): string
    {
        $dir = getenv('DASHBOARD_TRUTH_RUN_DIR');

        if (! is_string($dir) || $dir === '') {
            throw new LogicException('DASHBOARD_TRUTH_RUN_DIR is not set (bin/run sets it).');
        }

        return $dir;
    }

    /**
     * The dataset of this test: its data set value.
     */
    public function dataset(): string
    {
        $data = $this->providedData();
        $dataset = $data[0] ?? null;

        if (! is_string($dataset)) {
            throw new LogicException('A recording test takes the dataset name as its data set value.');
        }

        return $dataset;
    }

    public function prefix(): string
    {
        return WorldKeeper::prefix(self::runId(), $this->dataset());
    }

    public function sqlite(): string
    {
        return WorldKeeper::sqlite(self::runDir(), $this->dataset());
    }

    public function createApplication()
    {
        if (self::recording()) {
            $this->environment('REDIS_PREFIX', $this->prefix());
            $this->environment('HORIZON_PREFIX', $this->prefix().'horizon:');
            $directory = dirname($this->sqlite());

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            if (! is_file($this->sqlite())) {
                touch($this->sqlite());
            }
        }

        return parent::createApplication();
    }

    /**
     * @param  string  $uri
     * @param  array<string, string>  $headers
     */
    public function get($uri, array $headers = []): TestResponse
    {
        $response = parent::get($uri, $headers);

        if (str_ends_with((string) $uri, '/observability/metrics')) {
            $this->lastScrape = (string) $response->getContent();
        }

        return $response;
    }

    /**
     * The body of the last metrics scrape, consumed.
     */
    public function lastScrape(): string
    {
        $body = $this->lastScrape ?? throw new LogicException('No scrape was made.');
        $this->lastScrape = null;

        return $body;
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if (! self::recording()) {
            return;
        }

        $app['config']->set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => $this->sqlite(),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('database.default', self::CONNECTION);
        $app['config']->set('queue.batching.database', self::CONNECTION);
        $app['config']->set('queue.failed.database', self::CONNECTION);

        foreach (['recent', 'pending', 'completed', 'recent_failed', 'failed', 'monitored'] as $list) {
            $app['config']->set('horizon.trim.'.$list, self::TRIM_MINUTES);
        }
    }

    private function environment(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}
