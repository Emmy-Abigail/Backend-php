<?php

declare(strict_types=1);

namespace App\Application\Port\In\Tracking;

interface GetTrackingUseCase
{
    public function execute(string $trackingId): GetTrackingResult;
}
