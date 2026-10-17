<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Panels;

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Queries\AlertState;

/**
 * The hidden Business Text panel that plays an alarm tone and speaks the firing rule titles: Grafana plays no
 * sound itself. It sits above the Overview row, outside the rows, so it cannot be collapsed away; its script
 * hides it and lifts the grid by its row, because a panel takes at least one grid row.
 *
 * @internal
 */
final readonly class AlertSound
{
    public const HEIGHT = 1;

    public function __construct(
        private Common $common,
        private AlertState $alerts,
        private string $script,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function panel(): array
    {
        return [
            'datasource' => Common::prometheus(),
            'gridPos' => Common::gridPos(self::HEIGHT, 24, 0, 0),
            'id' => PanelRegistry::ALERT_SOUND,
            'options' => [
                'afterRender' => $this->script,
                'content' => ' ',
                'contentPartials' => [],
                'defaultContent' => ' ',
                'editor' => ['format' => 'auto', 'language' => 'html'],
                'editors' => ['afterRender'],
                'externalStyles' => [],
                'helpers' => '',
                'renderMode' => 'data',
                'styles' => '',
                'wrap' => false,
            ],
            // Firing rules: GRAFANA_ALERTS instances, one per queue for the per-queue rules
            'targets' => [$this->common->target('A', $this->alerts->seen('firing'), format: 'table')],
            'title' => '',
            'transparent' => true,
            'type' => 'marcusolsson-dynamictext-panel',
        ];
    }
}
