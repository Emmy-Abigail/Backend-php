<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Convierte un decimal en texto a un entero escalado (por ejemplo "2.50" con
 * 2 decimales es 250). Así el precio se calcula con enteros y sin errores de
 * redondeo de coma flotante.
 */
final class DecimalScale
{
    private const MAX_INTEGER_DIGITS = 6;

    public static function parse(string $value, int $decimals, string $field): int
    {
        $pattern = sprintf('/^(\d{1,%d})(?:\.(\d{1,%d}))?$/', self::MAX_INTEGER_DIGITS, $decimals);

        if (preg_match($pattern, $value, $matches) !== 1) {
            throw new InvalidPricingInput(sprintf(
                'El campo %s debe ser un número positivo con hasta %d %s',
                $field,
                $decimals,
                $decimals === 1 ? 'decimal' : 'decimales',
            ));
        }

        $fraction = str_pad($matches[2] ?? '', $decimals, '0');

        return ((int) $matches[1]) * (10 ** $decimals) + (int) $fraction;
    }
}
