<?php

declare(strict_types=1);

namespace App\Domain\Package;

/** Punto de entrega elegido en el mapa; null si el paquete solo tiene distrito. */
final class Coordinates
{
    /** @return array{lat: float, lng: float}|null */
    public static function toArray(mixed $lat, mixed $lng): ?array
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }
}
