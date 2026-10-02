<?php

declare(strict_types=1);

namespace App\Application\Service\Catalog;

use App\Application\Port\In\Catalog\GetGeographyUseCase;
use App\Application\Port\Out\Catalog\CatalogRepository;

final class GetGeographyService implements GetGeographyUseCase
{
    public function __construct(
        private CatalogRepository $catalogRepository,
    ) {
    }

    public function execute(): array
    {
        return $this->catalogRepository->getGeography();
    }
}