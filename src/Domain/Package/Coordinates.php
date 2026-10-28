<?php

declare(strict_types=1);

namespace App\Domain\Package;

/** Punto de entrega elegido en el mapa; null si el paquete solo tiene distrito. */
final class Coordinates
{
    /** Límites aproximados de Lima Metropolitana y Callao, con margen. */
    public const LIMA_LAT = [-12.60, -11.50];
    public const LIMA_LNG = [-77.30, -76.55];

    /** @return array{lat: float, lng: float}|null */
    public static function toArray(mixed $lat, mixed $lng): ?array
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    public static function isInsideLima(float $lat, float $lng): bool
    {
        return $lat >= self::LIMA_LAT[0] && $lat <= self::LIMA_LAT[1]
            && $lng >= self::LIMA_LNG[0] && $lng <= self::LIMA_LNG[1];
    }
}
