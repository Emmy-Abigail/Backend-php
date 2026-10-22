<?php

declare(strict_types=1);

namespace App\Domain\Package;

use InvalidArgumentException;

/** Valores de punto fijo exactos que coinciden con la precisión almacenada en MySQL. */
final class DecimalQuantity
{
    public static function units(mixed $value, int $scale): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException('Debe ser un numero decimal');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Debe ser un numero finito');
        }

        $text = is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR) : trim((string) $value);
        if (!preg_match('/^([0-9]{1,9})(?:\.([0-9]+))?$/D', $text, $parts)) {
            throw new InvalidArgumentException('Debe ser un numero decimal positivo');
        }
        $fraction = rtrim($parts[2] ?? '', '0');
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("Se permiten hasta {$scale} decimales");
        }

        return ((int) $parts[1]) * (10 ** $scale) + (int) str_pad($fraction, $scale, '0');
    }

    public static function format(int $units, int $scale): string
    {
        $factor = 10 ** $scale;

        return intdiv($units, $factor) . '.' . str_pad((string) ($units % $factor), $scale, '0', STR_PAD_LEFT);
    }
}
