<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Peso volumétrico = largo × ancho × alto / 5000
 * Peso facturable  = máximo entre el peso real y el volumétrico
 * Monto            = peso facturable × tarifa base por kg × factor de zona
 *
 * Todo se calcula con enteros escalados y el monto se redondea a 2 decimales
 * (half-up). Con una tarifa de hasta 999999.99 el producto más grande es de
 * unos 5 × 10^18, por debajo de PHP_INT_MAX.
 */
final class PriceCalculator
{
    /** Volumen en cm³ × 1000 (lados en décimas) por kg volumétrico: 5000 × 1000. */
    private const VOLUME_UNITS_PER_KG = 5_000_000;

    /** Peso real (centésimas de kg) comparado con el volumen: 5_000_000 / 100. */
    private const VOLUME_UNITS_PER_HUNDREDTH = 50_000;

    public function calculate(
        ShipmentMeasures $measures,
        int $originZoneId,
        int $destinationZoneId,
        Tariff $tariff,
    ): Quote {
        $factorTenths = ZoneFactorMatrix::factorTenths($originZoneId, $destinationZoneId);
        $volume = $measures->lengthTenths * $measures->widthTenths * $measures->heightTenths;

        $realIsBillable = $measures->weightHundredths * self::VOLUME_UNITS_PER_HUNDREDTH >= $volume;

        // Peso facturable como fracción de kg, para no redondear antes de cobrar.
        [$numerator, $denominator] = $realIsBillable
            ? [$measures->weightHundredths, 100]
            : [$volume, self::VOLUME_UNITS_PER_KG];

        $amountCents = self::divideRounded(
            $numerator * $tariff->baseRateCentsPerKg * $factorTenths,
            $denominator * 10,
        );

        $volumetricHundredths = self::divideRounded($volume, self::VOLUME_UNITS_PER_HUNDREDTH);
        $billableHundredths = $realIsBillable ? $measures->weightHundredths : $volumetricHundredths;

        return new Quote(
            $volumetricHundredths,
            $billableHundredths,
            $factorTenths,
            $tariff->baseRateCentsPerKg,
            $amountCents,
            $originZoneId,
            $destinationZoneId,
        );
    }

    private static function divideRounded(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);

        return 2 * ($numerator % $denominator) >= $denominator ? $quotient + 1 : $quotient;
    }
}
