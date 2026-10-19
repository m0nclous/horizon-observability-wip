<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Collectors\JobClassesCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\Sample;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Tests\Integration\Discovery\DiscoveryFixtures;
use Illuminate\Filesystem\Filesystem;

/*
| Collector job_classes: queue_job_classes{job_class} = 1 per discovered class, read from the job class cache, so a
| scrape costs a cache file read.
*/

beforeEach(function (): void {
    $this->bootstrap = sys_get_temp_dir().'/ho-discovery-collector-'.getmypid().'-'.bin2hex(random_bytes(3));
    mkdir($this->bootstrap.'/cache', 0755, true);
    $this->app->useBootstrapPath($this->bootstrap);

    config()->set('horizon-observability.job_class_paths', [DiscoveryFixtures::directory()]);
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(JobClassCache::class);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->bootstrap);
});

/**
 * @return array{families: list<MetricFamily>, samples: array<string, int|float>}
 */
function collectJobClasses(): array
{
    $families = [...app(JobClassesCollector::class)->collect(new ScrapeContext(CarbonImmutable::now(), 5.0))];
    $samples = [];

    foreach ($families as $family) {
        foreach ($family->samples() as $sample) {
            /** @var Sample $sample */
            $samples[$sample->labels['job_class']] = $sample->value;
        }
    }

    return ['families' => array_values($families), 'samples' => $samples];
}

it('is the job_classes collector', function (): void {
    expect(app(JobClassesCollector::class)->name())->toBe('job_classes')
        ->and(Metric::JobClasses->collector())->toBe('job_classes');
});

it('exports one series per discovered class with value 1', function (): void {
    $result = collectJobClasses();

    expect($result['families'])->toHaveCount(1)
        ->and($result['families'][0]->metric())->toBe(Metric::JobClasses)
        ->and($result['samples'])->toBe(array_fill_keys(DiscoveryFixtures::FOUND, 1));
});

it('reads the cache file instead of scanning the code', function (): void {
    file_put_contents($this->bootstrap.'/cache/horizon-observability.php', "<?php\n\nreturn ['version' => 1, 'generated_at' => 1, 'classes' => ['App\\\\Jobs\\\\OnlyInTheFile']];\n");

    expect(collectJobClasses()['samples'])->toBe(['App\\Jobs\\OnlyInTheFile' => 1]);
});

it('normalises the labels like every job_class label and exports each once', function (): void {
    $long = 'App\\Jobs\\'.str_repeat('A', 300);
    $classes = ['App\\Jobs\\Same', ' App\\Jobs\\Same ', "App\\Jobs\\Control\x1fChar", $long];
    file_put_contents($this->bootstrap.'/cache/horizon-observability.php', '<?php return '.var_export(['version' => 1, 'generated_at' => 1, 'classes' => $classes], true).';');

    $samples = collectJobClasses()['samples'];

    expect(array_keys($samples))->toBe(['App\\Jobs\\Same', 'App\\Jobs\\ControlChar', substr($long, 0, 197).'...'])
        ->and(array_values($samples))->toBe([1, 1, 1]);
});

it('exports an empty family when there is nothing to scan', function (): void {
    config()->set('horizon-observability.job_class_paths', []);
    $this->app->forgetInstance(Config::class);

    $result = collectJobClasses();

    expect($result['families'])->toHaveCount(1)
        ->and($result['families'][0]->isEmpty())->toBeTrue();
});
