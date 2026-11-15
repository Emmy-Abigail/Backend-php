<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use DateTimeImmutable;

/**
 * Reglas comunes de cálculo del dashboard (Demo 4, sección 1.2):
 * porcentajes y horas con 1 decimal; sin datos se devuelve null para que el cliente muestre «—».
 */
final class DashboardMath
{
    public static function percent(int $part, int $whole): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part * 100 / $whole, 1);
    }

    /** Porcentaje de una parte sobre el total, 0.0 cuando no hay total (para barras y donas). */
    public static function share(int $part, int $whole): float
    {
        return self::percent($part, $whole) ?? 0.0;
    }

    /** Horas transcurridas entre dos instantes; nunca negativas. */
    public static function hoursBetween(DateTimeImmutable $start, DateTimeImmutable $end): float
    {
        return max(0, $end->getTimestamp() - $start->getTimestamp()) / 3600;
    }

    /** @param list<float> $hours */
    public static function averageHours(array $hours): ?float
    {
        if ($hours === []) {
            return null;
        }

        return round(array_sum($hours) / count($hours), 1);
    }
}
