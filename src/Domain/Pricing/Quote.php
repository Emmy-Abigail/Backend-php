<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class Quote
{
    public function __construct(
        public int $volumetricWeightHundredths,
        public int $billableWeightHundredths,
        public int $factorTenths,
        public int $baseRateCentsPerKg,
        public int $amountCents,
        public int $originZoneId,
        public int $destinationZoneId,
    ) {
    }

    public function volumetricWeightKg(): float
    {
        return $this->volumetricWeightHundredths / 100;
    }

    public function billableWeightKg(): float
    {
        return $this->billableWeightHundredths / 100;
    }

    public function factor(): float
    {
        return $this->factorTenths / 10;
    }

    public function baseRatePerKg(): float
    {
        return $this->baseRateCentsPerKg / 100;
    }

    public function amount(): float
    {
        return $this->amountCents / 100;
    }
}
