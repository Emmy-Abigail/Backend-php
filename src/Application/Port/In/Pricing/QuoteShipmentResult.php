<?php

declare(strict_types=1);

namespace App\Application\Port\In\Pricing;

use App\Domain\Pricing\Quote;

final readonly class QuoteShipmentResult
{
    public function __construct(
        public Quote $quote,
        public int $originSedeId,
        public string $originSedeName,
        public string $originZoneCode,
        public string $destinationZoneCode,
    ) {
    }
}
