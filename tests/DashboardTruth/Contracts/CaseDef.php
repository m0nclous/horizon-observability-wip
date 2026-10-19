<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

use InvalidArgumentException;

/**
 * One case = one page load (design section 9): a dataset, a render, a range, the filters as `var-*` URL values (as
 * links and bookmarks set them), the browser's time zone (the dashboard's time zone is `browser`), the viewport width
 * and the view. A case never lists values or panels: every target, variable and DOM element of the page is asserted
 * from its one capture.
 */
final readonly class CaseDef
{
    public const SETS = ['pr', 'full'];

    public const VIEWS = ['normal', 'kiosk'];

    /**
     * @param  string  $from  a Grafana time (`now-1h`) or an absolute time in epoch milliseconds
     * @param  array<string, list<string>>  $vars  variable name => URL values (`var-<name>=<value>`, one per value)
     * @param  bool  $custom  a case that deliberately sets a value which is not an option of its variable; without it
     *                        such a value fails the case as `case setup` (design section 5.2)
     * @param  Category|null  $expect  a negative control: the case must fail, and only with this category (its
     *                                 checks run, no spec is asserted)
     */
    public function __construct(
        public string $id,
        public string $dataset,
        public Mode $mode,
        public string $from = 'now-1h',
        public string $to = 'now',
        public array $vars = [],
        public string $timezone = 'UTC',
        public int $width = 1920,
        public string $view = 'normal',
        public string $set = 'pr',
        public bool $custom = false,
        public string $purpose = '',
        public ?Category $expect = null,
    ) {
        if (preg_match('/^[A-Z][A-Za-z0-9-]{1,15}$/', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Bad case id "%s".', $id));
        }

        if (! in_array($view, self::VIEWS, true) || ! in_array($set, self::SETS, true)) {
            throw new InvalidArgumentException(sprintf('Case %s: bad view or set.', $id));
        }
    }

    /**
     * The query of the dashboard URL: the range, `refresh=1h` (stops auto-refresh under the fixed fake clock, design
     * X8), the filters and kiosk mode.
     */
    public function query(): string
    {
        $parts = ['orgId=1', 'from='.rawurlencode($this->from), 'to='.rawurlencode($this->to), 'refresh=1h'];

        foreach ($this->vars as $name => $values) {
            foreach ($values as $value) {
                $parts[] = 'var-'.rawurlencode($name).'='.rawurlencode($value);
            }
        }

        if ($this->view === 'kiosk') {
            $parts[] = 'kiosk';
        }

        return implode('&', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'dataset' => $this->dataset,
            'mode' => $this->mode->value,
            'from' => $this->from,
            'to' => $this->to,
            'vars' => (object) $this->vars,
            'timezone' => $this->timezone,
            'width' => $this->width,
            'view' => $this->view,
            'set' => $this->set,
            'custom' => $this->custom,
            'purpose' => $this->purpose,
            'expect' => $this->expect?->value,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $vars = [];

        foreach (is_array($data['vars'] ?? null) ? $data['vars'] : [] as $name => $values) {
            $vars[(string) $name] = array_values(array_map(strval(...), is_array($values) ? $values : [$values]));
        }

        return new self(
            id: (string) $data['id'],
            dataset: (string) $data['dataset'],
            mode: Mode::from((string) $data['mode']),
            from: (string) ($data['from'] ?? 'now-1h'),
            to: (string) ($data['to'] ?? 'now'),
            vars: $vars,
            timezone: (string) ($data['timezone'] ?? 'UTC'),
            width: (int) ($data['width'] ?? 1920),
            view: (string) ($data['view'] ?? 'normal'),
            set: (string) ($data['set'] ?? 'pr'),
            custom: (bool) ($data['custom'] ?? false),
            purpose: (string) ($data['purpose'] ?? ''),
            expect: is_string($data['expect'] ?? null) ? Category::from($data['expect']) : null,
        );
    }
}
