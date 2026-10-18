<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\HorizonUrl;
use DF\HorizonObservability\Http\RoutePath;
use DF\HorizonObservability\Install\AppUrl;
use DF\HorizonObservability\Install\DockerStack;
use DF\HorizonObservability\Install\EnvFile;
use DF\HorizonObservability\Install\SetupGuide;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\CheckStatus;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Support\EndpointUrl;
use DF\HorizonObservability\Support\FailureReporter;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SensitiveParameter;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * PUBLIC `horizon-observability:install` (Arch Q7, Addendum Q9' WP10, owner decision 12): everything it safely can,
 * so a newcomer gets from `composer require` to a dashboard with data in minutes.
 *
 * 1. Publishes `config/horizon-observability.php` (asks; keeps an existing file unless `--force` or confirmed).
 * 2. Writes a 40-character `HORIZON_OBSERVABILITY_TOKEN` to `.env` (or `--token`, which must be an RFC 6750 bearer
 *    token: written unquoted, anything else would not read back the same), checks that `.env` reads it back, and adds
 *    an empty placeholder to `.env.example`; an existing value is never overwritten; `--no-env` prints the token
 *    instead.
 * 3. Prints the endpoints, the Prometheus scrape job at 10 s, the Infinity datasource, the render command of each live
 *    mode and the stream mode steps (Grafana service account with role Editor); with `live.enabled` false only the
 *    `--live=off` render. The endpoints are where the routes answer: `APP_URL`, or the package domain.
 * 4. `--docker` writes `docker-compose.observability.yml` and `docker/observability/**`: Prometheus with the bearer
 *    token and Grafana 13 (`--grafana=12`: 12.4.13) with the plugins, both datasources, the rendered dashboard (the
 *    home page; `--live=off` when live is disabled) and the alert rules, pointed at the app (`--app-url`, by default
 *    `APP_URL` with a local host as `host.docker.internal`, port 8000 unless `APP_URL` has one; with a package domain
 *    and a local `APP_URL`, the domain, which the containers resolve to the Docker host). A changed file, and a
 *    dashboard that differs from the render, are kept unless `--force` or confirmed.
 * 5. `--demo` dispatches a burst of sample jobs (`horizon-observability:demo`).
 * 6. Runs the checks of `horizon-observability:check`, prints the problems with their fix lines, and ends with the next
 *    steps and the URL to open.
 *
 * Idempotent: run again, it changes nothing (without `--demo`). Exit 1 when a check finds an error or a step failed.
 */
final class InstallCommand extends Command
{
    public const TOKEN_ENV = 'HORIZON_OBSERVABILITY_TOKEN';

    public const TOKEN_LENGTH = 40;

    public const DEFAULT_GRAFANA_URL = 'http://localhost:3000';

    /**
     * The bearer token syntax of RFC 6750 (b64token): what `.env`, Docker Compose and the `Authorization` header
     * carry unquoted. No comma, so a token is never read as a rotation list.
     */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9._~+\/-]+=*$/';

    /**
     * @var string
     */
    protected $signature = 'horizon-observability:install
                            {--token= : Use this token instead of generating one (at least 16 characters)}
                            {--no-env : Do not write the token to .env and .env.example; print it instead}
                            {--force : Overwrite the published config and changed Docker files without asking}
                            {--docker : Also write docker-compose.observability.yml and docker/observability (Prometheus and Grafana, preprovisioned)}
                            {--grafana=13 : Grafana of the Docker stack: 13 (13.2.4) or 12 (12.4.13)}
                            {--app-url= : The app as Prometheus and Grafana reach it (default: APP_URL; with --docker a local host becomes host.docker.internal, port 8000 unless APP_URL has one)}
                            {--demo : Dispatch a short burst of sample jobs at the end (not in production)}';

    /**
     * @var string
     */
    protected $description = 'Publish the config, create a token and print the Prometheus and Grafana setup';

    private bool $failed = false;

    public function handle(Filesystem $files, Repository $config): int
    {
        $grafana = $this->text('grafana');
        $tokenOption = $this->text('token');
        $docker = $this->option('docker') === true;

        if (! isset(DockerStack::GRAFANA_IMAGES[$grafana])) {
            $this->components->error('--grafana must be 12 or 13.');

            return self::FAILURE;
        }

        if ($tokenOption !== '' && strlen($tokenOption) < Config::MIN_TOKEN_LENGTH) {
            $this->components->error(sprintf('--token must be at least %d characters long.', Config::MIN_TOKEN_LENGTH));

            return self::FAILURE;
        }

        if ($tokenOption !== '' && preg_match(self::TOKEN_PATTERN, $tokenOption) !== 1) {
            $this->components->error('--token may only contain letters, digits, - . _ ~ + / and = at the end (an RFC 6750 bearer token), so .env and the Authorization header carry it unchanged.');

            return self::FAILURE;
        }

        try {
            $app = $this->appUrl($config, $docker);
        } catch (InvalidArgumentException $e) {
            $this->components->error('--app-url: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Installing Horizon Observability.');

        $this->publishConfig($files);
        $token = $this->token($tokenOption === '' ? null : $tokenOption, $config);
        $live = $this->laravel->make(Config::class)->liveEnabled;
        $this->endpoints($config, $live);

        $job = $this->jobName($config);
        $apiPath = RoutePath::apiPath($config);
        $alias = $docker ? $this->hostAlias($config, $app) : null;

        if ($docker) {
            $prefix = $this->laravel->make(Config::class)->prefix();
            $this->docker($files, new DockerStack($this->projectName($config), $job, $app, $apiPath, $grafana, $prefix, $alias), $token, $live);
        }

        $grafanaUrl = $docker ? self::DEFAULT_GRAFANA_URL : $this->grafanaBase();
        // Your own Prometheus reaches the app where the endpoints answer (or --app-url), not through Docker's host gateway.
        $own = $docker && $this->text('app-url') === '' ? $this->endpointsUrl($config) : $app;
        $this->guide(new SetupGuide($job, $own, $apiPath, $grafanaUrl, live: $live), $docker, $job);

        if ($this->option('demo') === true) {
            $this->newLine();

            if ($this->call('horizon-observability:demo') !== self::SUCCESS) {
                $this->failed = true;
            }
        }

        $this->checks();
        $this->nextSteps($docker, $app, $grafanaUrl, $alias);

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function publishConfig(Filesystem $files): void
    {
        $target = $this->laravel->configPath('horizon-observability.php');
        $source = dirname(__DIR__, 2).'/config/horizon-observability.php';
        $relative = 'config/horizon-observability.php';

        if ($files->isFile($target)) {
            if ($files->get($target) === $files->get($source)) {
                $this->components->twoColumnDetail('Config', $relative.' <fg=gray>(unchanged)</>');

                return;
            }

            $overwrite = $this->option('force') === true || $this->confirm($relative.' exists and differs from the package defaults. Overwrite it?', false);

            if (! $overwrite) {
                $this->components->twoColumnDetail('Config', $relative.' <fg=gray>(kept)</>');

                return;
            }
        } elseif ($this->option('force') !== true && ! $this->confirm('Publish '.$relative.'?', true)) {
            $this->components->twoColumnDetail('Config', '<fg=gray>not published (the package defaults apply)</>');

            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->copy($source, $target);
        $this->components->twoColumnDetail('Config', $relative.' <fg=green>(published)</>');
    }

    /**
     * The token the endpoints accept after the install (for the Docker stack and the checks): the existing one, or
     * a new one written to `.env`. Applied to the running process as well, so the checks see it.
     */
    private function token(#[SensitiveParameter] ?string $option, Repository $config): ?string
    {
        $env = $this->laravel->environmentFilePath();
        $contents = is_file($env) ? (string) file_get_contents($env) : null;
        $existing = $contents === null ? null : EnvFile::value($contents, self::TOKEN_ENV);

        if ($existing !== null && $existing !== '') {
            $this->components->twoColumnDetail('Token', self::TOKEN_ENV.' <fg=gray>(already in .env, kept)</>');

            if ($option !== null && $option !== $existing) {
                $this->components->warn('--token was ignored: '.self::TOKEN_ENV.' is already set in .env and is never overwritten.');
            }

            // .env changed after this process loaded it (or the config is cached): check what the next start sees.
            if ($this->laravel->make(Config::class)->tokens === []) {
                $this->apply($config, $existing);
            }

            return $this->first($existing);
        }

        /** @var Config $settings */
        $settings = $this->laravel->make(Config::class);

        if ($settings->tokens !== [] || $settings->hasProblem('token')) {
            $this->components->twoColumnDetail('Token', self::TOKEN_ENV.' <fg=gray>(set outside .env, kept)</>');

            return $settings->tokens[0] ?? null;
        }

        $token = $option ?? Str::random(self::TOKEN_LENGTH);

        if ($this->option('no-env') === true) {
            $this->components->twoColumnDetail('Token', '<fg=yellow>not written (--no-env)</>');
            $this->line('  Add to the environment of the app: '.self::TOKEN_ENV.'='.$token);
        } elseif ($contents === null) {
            $this->components->twoColumnDetail('Token', '<fg=yellow>no .env file</>');
            $this->line('  Add to the environment of the app: '.self::TOKEN_ENV.'='.$token);
        } else {
            try {
                EnvFile::write($env, self::TOKEN_ENV, $token);

                $read = $this->readBack($env);

                if ($read === $token) {
                    $this->components->twoColumnDetail('Token', self::TOKEN_ENV.' <fg=green>(written to .env)</>');
                    $this->placeholder();
                } else {
                    $this->failed = true;
                    $this->components->error(sprintf(
                        'The next start of the app reads %s from .env as %s, not as written: fix .env (e.g. an unclosed quote above that line).',
                        self::TOKEN_ENV,
                        $read === null ? 'missing' : 'another value',
                    ));
                }
            } catch (Throwable $e) {
                $this->failed = true;
                $this->components->error(FailureReporter::describe($e).' Add '.self::TOKEN_ENV.'='.$token.' to the environment yourself.');
            }
        }

        $this->apply($config, $token);

        return $token;
    }

    /**
     * The token as the next start of the application reads `.env` (phpdotenv, as Laravel loads it), or null.
     *
     * @throws Throwable when `.env` cannot be parsed
     */
    private function readBack(string $env): ?string
    {
        $value = Dotenv::parse((string) file_get_contents($env))[self::TOKEN_ENV] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Use the token in this process too, so the checks see what the application sees after its next start.
     */
    private function apply(Repository $config, #[SensitiveParameter] string $token): void
    {
        $config->set('horizon-observability.token', $token);
        $this->laravel->forgetInstance(Config::class);

        if ($this->laravel->configurationIsCached()) {
            $this->components->warn('The configuration is cached: run php artisan config:cache (or config:clear) so the app reads the token.');
        }
    }

    /**
     * An empty `HORIZON_OBSERVABILITY_TOKEN=` in `.env.example`, when the file exists and has no such line.
     */
    private function placeholder(): void
    {
        $example = $this->laravel->basePath('.env.example');

        if (! is_file($example)) {
            return;
        }

        try {
            if (EnvFile::value((string) file_get_contents($example), self::TOKEN_ENV) === null) {
                file_put_contents($example, EnvFile::with((string) file_get_contents($example), self::TOKEN_ENV, ''));
                $this->components->twoColumnDetail('Placeholder', self::TOKEN_ENV.'= <fg=green>(added to .env.example)</>');
            }
        } catch (Throwable $e) {
            $this->components->warn('.env.example was not changed: '.FailureReporter::describe($e));
        }
    }

    private function endpoints(Repository $config, bool $live): void
    {
        $this->components->twoColumnDetail('Endpoints', EndpointUrl::to($config).($live ? '/{metrics,jobs/*,live}' : '/{metrics,jobs/*}'));
        $this->components->twoColumnDetail('Horizon', (new HorizonUrl($config))->url());
    }

    private function docker(Filesystem $files, DockerStack $stack, ?string $token, bool $live): void
    {
        $this->newLine();
        $this->line(sprintf('  <options=bold>Docker stack</> (Prometheus and %s, the app at %s):', $stack->grafanaImage(), OutputFormatter::escape($stack->app->toString())));

        foreach ($stack->files() as $relative => $contents) {
            $this->components->twoColumnDetail($relative, $this->writeFile($files, $relative, $contents));
        }

        $this->components->twoColumnDetail(DockerStack::RENDER_DIRECTORY.'/{dashboards,alerting}', $this->render($files, $stack, $live ? 'poll' : 'off'));

        if ($token === null) {
            $this->components->warn('No token is set: docker compose needs '.self::TOKEN_ENV.' in .env.');
        }
    }

    private function writeFile(Filesystem $files, string $relative, string $contents): string
    {
        $path = $this->laravel->basePath($relative);

        try {
            if (! $files->isFile($path)) {
                $files->ensureDirectoryExists(dirname($path));
                $files->put($path, $contents);

                return '<fg=green>written</>';
            }

            if ($files->get($path) === $contents) {
                return '<fg=gray>unchanged</>';
            }

            if ($this->option('force') !== true && ! $this->confirm($relative.' differs from the generated file. Overwrite it?', false)) {
                return '<fg=yellow>kept (changed; --force overwrites)</>';
            }

            $files->put($path, $contents);

            return '<fg=green>overwritten</>';
        } catch (Throwable $e) {
            $this->failed = true;

            return '<fg=red>failed: '.OutputFormatter::escape(FailureReporter::describe($e)).'</>';
        }
    }

    /**
     * The dashboard and alert rules of the Docker stack: rendered by `horizon-observability:grafana` with the app's
     * config, the Prometheus job, the provisioned datasource uid and the live mode (`off` when live is disabled, so no
     * tile asks for a missing `/live`), or else copied from the package's committed render (a `poll` render). A render
     * that differs (another live mode rendered by hand, or an older package) is kept unless `--force` or confirmed.
     */
    private function render(Filesystem $files, DockerStack $stack, string $live): string
    {
        $directory = $this->laravel->basePath(DockerStack::RENDER_DIRECTORY);
        $render = ['dashboards/horizon-observability.json', 'alerting/horizon-observability.json'];
        $rendered = array_filter($render, static fn (string $file): bool => $files->isFile($directory.'/'.$file)) === $render;

        if ($this->getApplication()?->has('horizon-observability:grafana') === true) {
            $arguments = ['path' => $directory, '--job' => $stack->job, '--datasource-uid' => DockerStack::PROMETHEUS_UID, '--live' => $live];

            try {
                if ($rendered && $this->callSilently('horizon-observability:grafana', [...$arguments, '--check' => true]) === self::SUCCESS) {
                    return '<fg=gray>unchanged</>';
                }

                if ($rendered && $this->option('force') !== true && ! $this->confirm(sprintf('The dashboard in %s differs from the --live=%s render. Render it again?', DockerStack::RENDER_DIRECTORY, $live), false)) {
                    return sprintf('<fg=yellow>kept (differs from the --live=%s render; --force renders it again)</>', $live);
                }

                if ($this->callSilently('horizon-observability:grafana', $arguments) === self::SUCCESS) {
                    return '<fg=green>rendered (--live='.$live.')</>';
                }
            } catch (Throwable $e) {
                $this->failed = true;

                return '<fg=red>failed: '.OutputFormatter::escape(FailureReporter::describe($e)).'</>';
            }

            $this->failed = true;

            return '<fg=red>failed: run php artisan horizon-observability:grafana '.DockerStack::RENDER_DIRECTORY.' --job='.$stack->job.' --live='.$live.'</>';
        }

        $source = dirname(__DIR__, 2).'/resources/grafana';

        if ($live !== 'poll' || $this->laravel->make(Config::class)->prefix() !== Config::DEFAULT_NAMESPACE.'_' || ! $files->isFile($source.'/'.$render[0]) || ! $files->isFile($source.'/'.$render[1])) {
            $this->failed = true;

            return '<fg=red>no --live='.$live.' render available in this version</>';
        }

        $statuses = array_map(fn (string $file): string => strip_tags($this->writeFile($files, DockerStack::RENDER_DIRECTORY.'/'.$file, $files->get($source.'/'.$file))), $render);

        return '<fg=green>copied the package render</> ('.implode(', ', array_unique($statuses)).')';
    }

    private function guide(SetupGuide $guide, bool $docker, string $job): void
    {
        $this->newLine();
        $this->line($docker
            ? '  <options=bold>Your own Prometheus and Grafana</> (later, outside Docker):'
            : '  <options=bold>Prometheus</>: add this job under scrape_configs and store the token in the credentials file:');

        if ($docker) {
            $this->line('  Prometheus job:');
        }

        $this->block($guide->prometheusJob());

        $this->line('  <options=bold>Grafana</> 12.3+ or 13: install the plugins yesoreyeram-infinity-datasource 4.1.1+ and marcusolsson-dynamictext-panel 6.3.0+,');
        $this->line('  provision this datasource (the Prometheus one is your default datasource):');
        $this->block($guide->infinityDatasource());

        $this->line('  Render the dashboard and the alert rules for your live mode, then provision '.$guide->renderPath.'/dashboards and '.$guide->renderPath.'/alerting:');

        foreach ($guide->renderCommands() as $command => $meaning) {
            $this->line('    '.$command);
            $this->line('      <fg=gray># '.$meaning.'</>');
        }

        if (! $guide->live) {
            return;
        }

        if ($docker) {
            $this->line('  Another live mode for the Docker stack (Grafana reloads the dashboard within 10 s):');
            $this->line(sprintf('    php artisan horizon-observability:grafana %s --job=%s --datasource-uid=%s --live=stream', DockerStack::RENDER_DIRECTORY, $job, DockerStack::PROMETHEUS_UID));
        }

        $this->line('  <options=bold>Stream mode</> (optional, 1 s tiles): a Grafana service account with role Editor:');

        foreach ($guide->streamSteps() as $step) {
            $this->line('    '.OutputFormatter::escape($step));
        }
    }

    private function block(string $text): void
    {
        foreach (explode("\n", $text) as $line) {
            $this->line('    <fg=gray>'.OutputFormatter::escape($line).'</>');
        }
    }

    /**
     * The checks of `horizon-observability:check`, problems only.
     */
    private function checks(): void
    {
        $this->newLine();

        try {
            $results = $this->laravel->make(Diagnostics::class)->check();
        } catch (Throwable $e) {
            $this->failed = true;
            $this->components->error('The checks failed to run: '.FailureReporter::describe($e));

            return;
        }

        $problems = array_values(array_filter($results, static fn (CheckResult $result): bool => $result->status->severity() > 0));
        $errors = count(array_filter($problems, static fn (CheckResult $result): bool => $result->status === CheckStatus::Error));

        $this->line(sprintf(
            '  <options=bold>Check</>: %d %s, %d %s (details: php artisan horizon-observability:check)',
            $errors,
            $errors === 1 ? 'error' : 'errors',
            count($problems) - $errors,
            count($problems) - $errors === 1 ? 'warning' : 'warnings',
        ));

        foreach ($problems as $problem) {
            $tag = $problem->status === CheckStatus::Error ? '<fg=red;options=bold>ERROR</>' : '<fg=yellow;options=bold>WARN</>';
            $this->line(sprintf('    %s %s, %s: %s', $tag, $problem->section, OutputFormatter::escape($problem->name), OutputFormatter::escape($problem->message)));

            if ($problem->fix !== null) {
                $this->line('      Fix: '.OutputFormatter::escape($problem->fix));
            }
        }

        if ($errors > 0) {
            $this->failed = true;
        }
    }

    private function nextSteps(bool $docker, AppUrl $app, string $grafanaUrl, ?string $alias): void
    {
        $this->newLine();
        $this->line('  <options=bold>Next steps</>:');
        $steps = [];

        if ($docker) {
            if ($app->host === 'host.docker.internal' || $alias !== null) {
                $steps[] = sprintf('php artisan serve --host=0.0.0.0 --port=%d   <fg=gray># so Docker reaches the app at %s</>', $app->port ?? 80, OutputFormatter::escape($app->toString()));
            }

            $steps[] = 'php artisan horizon';
            $steps[] = 'docker compose -f '.DockerStack::COMPOSE_FILE.' up -d';
        } else {
            $steps[] = 'Add the scrape job above to Prometheus with the token from .env';
            $steps[] = 'Install the plugins, provision the datasource and render the dashboard (commands above)';
            $steps[] = 'php artisan horizon';
        }

        foreach ($steps as $index => $step) {
            $this->line(sprintf('    %d. %s', $index + 1, $step));
        }

        $this->newLine();
        // The provisioned dashboard itself: the alert annotations are linked to its uid (Grafana 13 serves a default
        // home dashboard file as a copy with another uid).
        $dashboard = rtrim($grafanaUrl, '/').'/d/'.DockerStack::DASHBOARD_UID;
        $open = $docker ? $dashboard : (is_string($url = $this->laravel->make(Config::class)->grafanaUrl) ? $url : $dashboard);
        $this->components->info('Open '.$open);
    }

    /**
     * The app as Prometheus and Grafana reach it: `--app-url`, else where the endpoints answer ({@see EndpointUrl}:
     * `APP_URL`, or the package domain); with `--docker` a local host becomes `host.docker.internal`, and a package
     * domain of a local `APP_URL` is kept with port 8000 (resolved to the Docker host, {@see self::hostAlias()});
     * asked interactively.
     *
     * @throws InvalidArgumentException for an invalid `--app-url`
     */
    private function appUrl(Repository $config, bool $docker): AppUrl
    {
        $option = $this->text('app-url');

        if ($option !== '') {
            return AppUrl::parse($option);
        }

        $app = $this->endpointsUrl($config);

        if (! $docker) {
            return $app;
        }

        $default = ($this->localDomain($config) === null ? $app->forDocker() : new AppUrl($app->scheme, $app->host, $app->port ?? 8000, $app->path))->toString();
        $answer = $this->ask('At which URL do Prometheus and Grafana in Docker reach the app?', $default);

        try {
            return AppUrl::parse(is_string($answer) && trim($answer) !== '' ? $answer : $default);
        } catch (InvalidArgumentException) {
            $this->components->warn(sprintf('That is not an http(s) URL; using %s.', $default));

            return AppUrl::parse($default);
        }
    }

    /**
     * Where the endpoints answer: `APP_URL`, or the package domain with the scheme and port of `APP_URL`.
     */
    private function endpointsUrl(Repository $config): AppUrl
    {
        try {
            return AppUrl::parse(EndpointUrl::root($config));
        } catch (InvalidArgumentException) {
            return AppUrl::parse('http://localhost');
        }
    }

    /**
     * The package domain when the app runs locally (a local `APP_URL`) and the domain is a host name that is not
     * itself local: the containers must resolve it to the Docker host, because the routes answer only on it.
     */
    private function localDomain(Repository $config): ?string
    {
        $domain = EndpointUrl::domain($config);

        if ($domain === null || ! $this->configuredAppUrl($config)->isLocal() || in_array(strtolower($domain), AppUrl::LOCAL_HOSTS, true)) {
            return null;
        }

        return preg_match(DockerStack::HOST_PATTERN, $domain) === 1 ? $domain : null;
    }

    /**
     * The host the Docker stack maps to the Docker host: the local package domain, when the stack reaches the app on it.
     */
    private function hostAlias(Repository $config, AppUrl $app): ?string
    {
        $domain = $this->localDomain($config);

        return $domain !== null && strcasecmp($domain, $app->host) === 0 ? $domain : null;
    }

    /**
     * `APP_URL`, or http://localhost when it is not an http(s) URL.
     */
    private function configuredAppUrl(Repository $config): AppUrl
    {
        $configured = $config->get('app.url');

        try {
            return AppUrl::parse(is_string($configured) && $configured !== '' ? $configured : 'http://localhost');
        } catch (InvalidArgumentException) {
            return AppUrl::parse('http://localhost');
        }
    }

    private function jobName(Repository $config): string
    {
        $name = $config->get('app.name');
        $slug = Str::slug(is_string($name) ? $name : '');

        return ($slug === '' ? 'app' : $slug).'-horizon';
    }

    private function projectName(Repository $config): string
    {
        $name = $config->get('app.name');
        $slug = Str::slug(is_string($name) ? $name : '');

        return ($slug === '' ? 'app' : $slug).'-observability';
    }

    /**
     * Grafana's base URL from `grafana.url` (scheme, host and port of the dashboard URL), else localhost:3000.
     */
    private function grafanaBase(): string
    {
        $url = $this->laravel->make(Config::class)->grafanaUrl;

        if ($url === null) {
            return self::DEFAULT_GRAFANA_URL;
        }

        $parts = parse_url($url);

        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : self::DEFAULT_GRAFANA_URL;
    }

    /**
     * The first token of a comma-separated list.
     */
    private function first(#[SensitiveParameter] string $tokens): string
    {
        return trim(explode(',', $tokens)[0]);
    }

    private function text(string $option): string
    {
        $value = $this->option($option);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
