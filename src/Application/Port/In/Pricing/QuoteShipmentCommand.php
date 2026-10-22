<?php

declare(strict_types=1);

namespace App\Application\Port\In\Pricing;

final readonly class QuoteShipmentCommand
{
    /**
     * Los números llegan como texto decimal para no perder precisión.
     */
    public function __construct(
        public int $originSedeId,
        public string $destinationUbigeo,
        public string $weightKg,
        public string $lengthCm,
        public string $widthCm,
        public string $heightCm,
    ) {
    }
}
