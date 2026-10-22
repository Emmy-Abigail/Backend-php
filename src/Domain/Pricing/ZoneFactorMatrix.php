<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * Factor de zona por desplazamiento, en décimas (10 = 1.0, 12 = 1.2, 15 = 1.5).
 * Los ids de zona son los de la tabla zonas: 1 Norte, 2 Centro, 3 Sur, 4 Este, 5 Oeste.
 */
final class ZoneFactorMatrix
{
    public const CENTRO = 2;

    private const KNOWN_ZONES = [1, 2, 3, 4, 5];

    private const SAME_ZONE = 10;
    private const CENTRO_WITH_ANY = 12;
    private const BETWEEN_PERIPHERALS = 15;

    public static function factorTenths(int $originZoneId, int $destinationZoneId): int
    {
        foreach ([$originZoneId, $destinationZoneId] as $zoneId) {
            if (!in_array($zoneId, self::KNOWN_ZONES, true)) {
                throw new InvalidArgumentException(sprintf('La zona %d no existe', $zoneId));
            }
        }

        if ($originZoneId === $destinationZoneId) {
            return self::SAME_ZONE;
        }

        if ($originZoneId === self::CENTRO || $destinationZoneId === self::CENTRO) {
            return self::CENTRO_WITH_ANY;
        }

        return self::BETWEEN_PERIPHERALS;
    }
}
