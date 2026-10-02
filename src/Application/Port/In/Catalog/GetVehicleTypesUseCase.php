<?php

declare(strict_types=1);

namespace App\Application\Port\In\Catalog;

use App\Domain\Catalog\VehicleType;

interface GetVehicleTypesUseCase
{
    /**
     * @return list<VehicleType>
     */
    public function execute(): array;
}