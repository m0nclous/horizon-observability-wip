<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use DF\HorizonObservability\Grafana\Panels\Common;
use DF\HorizonObservability\Grafana\Queries\BatchState;
use DF\HorizonObservability\Grafana\Queries\Selection;
use stdClass;

/**
 * The dashboard variables, in toolbar order: Supervisor and Batch choose what to look at, Queue and Job narrow
 * down within them. The others are hidden.
 *
 * @internal
 */
final readonly class Variables
{
    /**
     * The filter variables, in toolbar order; the Reset link sets them to All.
     */
    public const FILTERS = ['supervisor', 'batch_kind', 'queue', 'job_class', 'supervisor_title', 'batch_title'];

    /**
     * Supervisors and their queues are taken from the last day, so they are known while Horizon is down.
     */
    public const KNOWN_FOR = '1d';

    /**
     * horizon_info is looked up a week back from the end of the period: the variable is evaluated at the end
     * of the period, where the metric may not exist for a past period.
     */
    private const INFO_LOOKBACK = '7d';

    public function __construct(
        private Promql $promql,
        private Selection $selection,
        private BatchState $batches,
        private DashboardOptions $options,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(array_filter([
            // The Prometheus datasource by type, not uid: the dashboard imports into any Grafana unchanged
            $this->datasource('datasource', 'prometheus', $this->options->prometheusDatasourceRegex),
            // Hidden: the Prometheus job (scrape job of the package endpoint) every query is scoped to, so one
            // Grafana serves several applications; All by default, ?var-prometheus_job=<job> or --job pins one
            $this->options->legacy ? null : $this->scope(),
            $this->filter('supervisor', 'Supervisor', $this->supervisorQuery(), '/supervisor="([^"]+)"/', '.*'),
            // Kinds with batches in the period; the batch tables repeat by it. Batches have no queue or
            // supervisor, so the filter does not narrow the other panels.
            $this->filter('batch_kind', 'Batch', Promql::call('query_result', $this->batches->kinds()), '/batch="([^"]+)"/', '.*'),
            // "All" is the listed queues, not .*: so the Supervisor filter works on every panel (queue metrics
            // have no supervisor label)
            $this->filter('queue', 'Queue', $this->queueQuery(), '/queue="([^"]+)"/'),
            // "All" is the listed jobs, not .*: so the Batch filter works on every panel. The text is the class
            // without its namespace, the value the full class name.
            $this->filter('job_class', 'Job', $this->jobQuery(), '/display="(?<text>[^"]+)".*job_class="(?<value>[^"]+)"/'),
            // Hidden: the queue table repeats by it, the text is the table title
            $this->hiddenFilter('supervisor_title', $this->supervisorTitleQuery(), '/display="(?<text>[^"]+)".*supervisor="(?<value>[^"]+)"/'),
            // Hidden: the batch tables repeat by it, the text is the table title
            $this->hiddenFilter('batch_title', $this->batchTitleQuery(), '/batch="(?<value>[^"]+)".*display="(?<text>[^"]+)"/'),
            // Hidden: the Horizon URL of this environment for the Horizon link, exported by the application. Not
            // in the URL: a dashboard link opened in another environment would lead to the wrong Horizon.
            $this->info('horizon_url', 'url'),
            // Hidden: the speech service for alert phrases, exported like horizon_url; without it only the tone
            $this->info('tts_url', 'tts_url'),
            // Hidden: the path of the application's job list API (horizon-observability.path), exported like
            // horizon_url; the Infinity URLs are relative to the datasource URL: ${api_path}/jobs/<list>
            $this->options->legacy ? null : $this->info('api_path', 'api_path'),
            // Hidden: the Infinity datasource with the application's job lists, by type: in a production Grafana
            // the datasource is created in the UI and has its own uid
            $this->datasource('app_api', 'yesoreyeram-infinity-datasource', $this->options->infinityDatasourceRegex),
        ]));
    }

    /**
     * The hidden `prometheus_job` variable (Arch Q5.2): the jobs that export the Horizon status, "All" as `.+`.
     *
     * @return array<string, mixed>
     */
    private function scope(): array
    {
        $query = Promql::call('label_values', Promql::vector($this->promql->metric('horizon_status')), 'job');
        $job = $this->options->job;

        return [
            'current' => $job === null ? ['text' => 'All', 'value' => '$__all'] : ['text' => $job, 'value' => $job],
            'datasource' => Common::prometheus(),
            'definition' => $query,
            'hide' => 2,
            'includeAll' => true,
            'allValue' => '.+',
            'multi' => false,
            'name' => 'prometheus_job',
            'options' => [],
            'query' => $query,
            'refresh' => 1,
            'regex' => '',
            'sort' => 1,
            'type' => 'query',
        ];
    }

    /**
     * Supervisor names known within a day.
     */
    private function supervisorQuery(): string
    {
        return Promql::call('query_result', Promql::group(
            Promql::range('last_over_time', $this->promql->selector('horizon_supervisor_processes'), self::KNOWN_FOR),
            'supervisor',
        ));
    }

    /**
     * Queues of the selected supervisors, and with Supervisor "All" also queues without a supervisor: nobody
     * works their pending jobs. With a batch selected only the queues of its jobs.
     */
    private function queueQuery(): string
    {
        $queues = Promql::group($this->promql->selector('queue_pending_jobs'), 'queue');
        $supervised = fn (string ...$matchers): string => Promql::group(
            Promql::range('last_over_time', $this->promql->selector('horizon_supervisor_processes', ...$matchers), self::KNOWN_FOR),
            'queue',
        );

        $rows = Promql::either(
            Promql::andOn($queues, $supervised(Promql::re('supervisor', '$supervisor')), 'queue'),
            Promql::paren(Promql::andOn(Promql::unlessOn($queues, $supervised(), 'queue'), Selection::isAll('supervisor'))),
        );

        return Promql::call('query_result', $this->selection->batchFiltered($rows, 'queue'));
    }

    /**
     * Job classes seen in the selected queues, and classes of the code that never ran (queue "\u{2014}", shown only
     * while the filters are "All"); with a batch selected only the classes of its batches. One option per class
     * even when it runs in several queues; the text is the class without its namespace.
     */
    private function jobQuery(): string
    {
        $families = ['queue_jobs_total', 'queue_jobs_pending', 'queue_jobs_reserved'];
        $seen = Promql::group($this->promql->anyOf($families, Promql::re('queue', '$queue')), 'queue', 'job_class');
        $neverRan = Promql::withLabel(
            Promql::unlessOn(
                Promql::group($this->promql->selector('queue_job_classes'), 'job_class'),
                Promql::group($this->promql->anyOf($families), 'job_class'),
                'job_class',
            ),
            'queue',
            Glyph::DASH,
        );
        $rows = $this->selection->batchFiltered(
            Promql::either($seen, Promql::andOn($neverRan, Selection::unfiltered())),
            'job_class',
        );

        return Promql::call('query_result', Promql::group(
            Promql::labelReplace($rows, 'display', '$1', 'job_class', '(?:.*\\\\)?([^\\\\]*)'),
            'job_class',
            'display',
        ));
    }

    /**
     * Value: the supervisor name; text: the queue table title "name \u{00B7} Processes: [min: 1, max: 10] \u{00B7} Balancing:
     * Auto \u{00B7} Connection: redis". Titles show only variables, which Grafana refreshes on page load and time
     * range change, so the title holds only settings that change with a deploy; current processes are in the
     * table. Numbers become labels through count_values, mode and connection are labels of
     * horizon_supervisor_info. Supervisors are the selected ones with selected queues, known within a day;
     * while Horizon is down the text is just the name. Without supervisors one table titled "\u{2014}", not "All".
     */
    private function supervisorTitleQuery(): string
    {
        $known = Promql::group(Promql::range(
            'last_over_time',
            $this->promql->selector('horizon_supervisor_processes', Promql::re('supervisor', '$supervisor'), Promql::re('queue', '$queue')),
            self::KNOWN_FOR,
        ), 'supervisor');
        $processes = fn (string $label, string $family): string => Promql::countValues(
            $label,
            Promql::max($this->promql->selector($family), 'supervisor'),
            'supervisor',
        );
        $facts = Promql::binary(
            Promql::binary($processes('min', 'horizon_supervisor_min_processes'), '*', $processes('max', 'horizon_supervisor_max_processes'), on: ['supervisor'], groupLeft: ['max']),
            '*',
            $this->promql->selector('horizon_supervisor_info'),
            on: ['supervisor'],
            groupLeft: ['balance', 'connection'],
        );

        foreach (['auto', 'simple', 'off'] as $mode) {
            $facts = Promql::labelReplace($facts, 'balancing', ucfirst($mode), 'balance', $mode);
        }

        $dot = ' '.Glyph::DOT.' ';
        $title = Promql::labelReplace(
            Promql::labelJoin($facts, 'joined', ';', 'supervisor', 'min', 'max', 'balancing', 'connection'),
            'display',
            '$1'.$dot.'Processes: [min: $2, max: $3]'.$dot.'Balancing: $4'.$dot.'Connection: $5',
            'joined',
            '(.*);(.*);(.*);(.*);(.*)',
        );

        return Promql::call('query_result', Promql::orOn(
            Promql::orOn(
                Promql::paren(Promql::andOn($title, $known, 'supervisor')),
                Promql::labelReplace($known, 'display', '$1', 'supervisor', '(.*)'),
                'supervisor',
            ),
            Promql::withLabel(Promql::withLabel('vector(1)', 'supervisor', Glyph::DASH), 'display', Glyph::DASH),
        ));
    }

    /**
     * Value: a batch kind of the Batch filter; text: the batch table title "kind \u{00B7} Queue: slow \u{00B7} Connection:
     * redis". Queues and connections are labels of queue_batch_info, comma-separated when there are several;
     * without links within a week only the kind. Without kinds in the period one table titled "\u{2014}", not "All".
     */
    private function batchTitleQuery(): string
    {
        $kinds = $this->batches->selectedKinds();
        $info = $this->promql->selector('queue_batch_info');
        $dot = ' '.Glyph::DOT.' ';
        $title = Promql::labelReplace(
            Promql::labelJoin(Promql::andOn($info, $kinds, 'batch'), 'display', "\t", 'batch', 'queue', 'connection'),
            'display',
            '$1'.$dot.'Queue: $2'.$dot.'Connection: $3',
            'display',
            "([^\t]*)\t([^\t]*)\t(.*)",
        );

        return Promql::call('query_result', Promql::orOn(
            Promql::orOn($title, Promql::labelReplace(Promql::unlessOn($kinds, $info, 'batch'), 'display', '$1', 'batch', '(.*)'), 'batch'),
            Promql::withLabel(Promql::withLabel('vector(1)', 'batch', Glyph::DASH), 'display', Glyph::DASH),
        ));
    }

    /**
     * A visible multi-value filter starting at All.
     *
     * @return array<string, mixed>
     */
    private function filter(string $name, string $label, string $query, string $regex, ?string $allValue = null): array
    {
        $variable = [
            'current' => $this->allSelected(),
            'datasource' => Common::prometheus(),
            'definition' => $query,
            'includeAll' => true,
        ];

        if ($allValue !== null) {
            $variable['allValue'] = $allValue;
        }

        return [
            ...$variable,
            'label' => $label,
            'multi' => true,
            'name' => $name,
            'options' => [],
            'query' => $query,
            'refresh' => 2,
            'regex' => $regex,
            'sort' => 1,
            'type' => 'query',
        ];
    }

    /**
     * A hidden multi-value variable that panels repeat by; its text is the panel title.
     *
     * @return array<string, mixed>
     */
    private function hiddenFilter(string $name, string $query, string $regex): array
    {
        return [
            'current' => $this->allSelected(),
            'datasource' => Common::prometheus(),
            'definition' => $query,
            'hide' => 2,
            'includeAll' => true,
            'allValue' => '.*',
            'multi' => true,
            'name' => $name,
            'options' => [],
            'query' => $query,
            'refresh' => 2,
            'regex' => $regex,
            'sort' => 1,
            'type' => 'query',
        ];
    }

    /**
     * A hidden variable with one label of horizon_info, refreshed on page load.
     *
     * @return array<string, mixed>
     */
    private function info(string $name, string $label): array
    {
        $query = Promql::call('query_result', Promql::group(
            Promql::range('last_over_time', $this->promql->selector('horizon_info'), self::INFO_LOOKBACK),
            $label,
        ));

        return [
            'current' => new stdClass,
            'datasource' => Common::prometheus(),
            'definition' => $query,
            'hide' => 2,
            'includeAll' => false,
            'multi' => false,
            'name' => $name,
            'options' => [],
            'query' => $query,
            'refresh' => 1,
            'regex' => '/'.$label.'="([^"]+)"/',
            'skipUrlSync' => true,
            'type' => 'query',
        ];
    }

    /**
     * A hidden datasource variable of one plugin type.
     *
     * @return array<string, mixed>
     */
    private function datasource(string $name, string $type, string $regex): array
    {
        return [
            'current' => new stdClass,
            'hide' => 2,
            'includeAll' => false,
            'multi' => false,
            'name' => $name,
            'options' => [],
            'query' => $type,
            'refresh' => 1,
            'regex' => $regex,
            'skipUrlSync' => true,
            'type' => 'datasource',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function allSelected(): array
    {
        return ['text' => ['All'], 'value' => ['$__all']];
    }
}
