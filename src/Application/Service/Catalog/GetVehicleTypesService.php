<?php

declare(strict_types=1);

namespace App\Application\Service\Catalog;

use App\Application\Port\In\Catalog\GetVehicleTypesUseCase;
use App\Application\Port\Out\Catalog\CatalogRepository;

final class GetVehicleTypesService implements GetVehicleTypesUseCase
{
    public function __construct(
        private CatalogRepository $catalogRepository,
    ) {
    }

    public function execute(): array
    {
        return $this->catalogRepository->getVehicleTypes();
    }
}