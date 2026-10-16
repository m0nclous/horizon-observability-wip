<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana;

/**
 * Вид батча для меток метрик: имя без чисел и uuid («campaign_launch_15_2026-10-15» → «campaign_launch»),
 * без имени — «unnamed». Отдельный батч в метку не попадает, иначе каждый стал бы новым рядом.
 */
class BatchKind
{
    public static function of(?string $name): string
    {
        $kind = preg_replace(
            ['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/\d+/', '/[\s_.:-]{2,}/'],
            ['', '', '_'],
            (string) $name,
        );

        return trim((string) $kind, " _.:-") ?: 'unnamed';
    }
}
