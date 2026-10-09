<?php

declare(strict_types=1);

namespace App\Domain\User;

final class PlacaPolicy
{
    private const PATTERNS = [
        'MOTORIZADO' => '/^([A-Z]{2}\d{4}|\d{4}[A-Z]{2})$/',
        'AUTO' => '/^[A-Z]{3}\d{3}$/',
        'CAMION' => '/^[A-Z]{3}\d{3}$/',
    ];

    private const DEFAULT_PATTERN = '/^(?=.*[A-Z])[A-Z0-9]{6,7}$/';

    public static function esValidaPara(string $codigoTipoVehiculo, string $placa): bool
    {
        $pattern = self::PATTERNS[$codigoTipoVehiculo] ?? self::DEFAULT_PATTERN;

        return preg_match($pattern, $placa) === 1;
    }
}
