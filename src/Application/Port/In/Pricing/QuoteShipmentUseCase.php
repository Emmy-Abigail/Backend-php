<?php

declare(strict_types=1);

namespace App\Application\Port\In\Pricing;

interface QuoteShipmentUseCase
{
    public function execute(QuoteShipmentCommand $command): QuoteShipmentResult;
}
