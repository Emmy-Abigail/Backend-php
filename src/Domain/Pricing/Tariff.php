<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Tarifa base vigente por kg. Se guarda el id porque cada paquete registra
 * con qué tarifa se cobró (columna paquetes.id_tarifa).
 */
final readonly class Tariff
{
    public function __construct(
        public int $id,
        public int $baseRateCentsPerKg,
    ) {
        if ($baseRateCentsPerKg <= 0) {
            throw new InvalidPricingInput('La tarifa base debe ser mayor que 0');
        }
    }

    public static function fromDecimalString(int $id, string $baseRatePerKg): self
    {
        return new self($id, DecimalScale::parse($baseRatePerKg, 2, 'tarifa_base_kg'));
    }
}
