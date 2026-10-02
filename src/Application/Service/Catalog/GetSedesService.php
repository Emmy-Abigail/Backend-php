<?php

declare(strict_types=1);

namespace App\Application\Service\Catalog;

use App\Application\Port\In\Catalog\GetSedesUseCase;
use App\Application\Port\Out\Catalog\CatalogRepository;

final class GetSedesService implements GetSedesUseCase
{
    public function __construct(
        private CatalogRepository $catalogRepository,
    ) {
    }

    public function execute(): array
    {
        return $this->catalogRepository->getSedes();
    }
}