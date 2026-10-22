<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Peso y dimensiones de un envío, ya validados contra los límites físicos.
 * El peso se guarda en centésimas de kg y cada lado en décimas de cm.
 */
final readonly class ShipmentMeasures
{
    public const MIN_WEIGHT_HUNDREDTHS = 10;
    public const MAX_WEIGHT_HUNDREDTHS = 5000;
    public const MIN_SIDE_TENTHS = 100;
    public const MAX_SIDE_TENTHS = 1500;

    private function __construct(
        public int $weightHundredths,
        public int $lengthTenths,
        public int $widthTenths,
        public int $heightTenths,
    ) {
    }

    public static function fromDecimalStrings(
        string $weightKg,
        string $lengthCm,
        string $widthCm,
        string $heightCm,
    ): self {
        $weight = DecimalScale::parse($weightKg, 2, 'peso_kg');
        $length = DecimalScale::parse($lengthCm, 1, 'largo_cm');
        $width = DecimalScale::parse($widthCm, 1, 'ancho_cm');
        $height = DecimalScale::parse($heightCm, 1, 'alto_cm');

        if ($weight < self::MIN_WEIGHT_HUNDREDTHS || $weight > self::MAX_WEIGHT_HUNDREDTHS) {
            throw new InvalidPricingInput('El campo peso_kg debe estar entre 0.1 y 50');
        }

        foreach (['largo_cm' => $length, 'ancho_cm' => $width, 'alto_cm' => $height] as $field => $side) {
            if ($side < self::MIN_SIDE_TENTHS || $side > self::MAX_SIDE_TENTHS) {
                throw new InvalidPricingInput(sprintf('El campo %s debe estar entre 10 y 150', $field));
            }
        }

        return new self($weight, $length, $width, $height);
    }
}
