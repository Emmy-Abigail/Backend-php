<?php

declare(strict_types=1);

namespace App\Application\Service\Dashboard;

use App\Application\Port\In\Dashboard\FailedDeliveriesResult;
use App\Application\Port\In\Dashboard\ListFailedDeliveriesQuery;
use App\Application\Port\In\Dashboard\ListFailedDeliveriesUseCase;
use App\Application\Port\Out\Dashboard\DashboardReadRepository;
use App\Application\Port\Out\Dashboard\DashboardScope;

/** Ventana «Ver todos» de fallidos: mismo rango y sede que el dashboard, paginada. */
final readonly class ListFailedDeliveriesService implements ListFailedDeliveriesUseCase
{
    public function __construct(
        private DashboardReadRepository $repository,
        private DashboardAccess $access,
    ) {
    }

    public function execute(ListFailedDeliveriesQuery $query): FailedDeliveriesResult
    {
        $sede = $this->access->sedeFor($query->user, $query->sedeId);
        $scope = new DashboardScope($query->from, $query->to, $sede['id'] ?? null);

        $total = $this->repository->countFailedDeliveries($scope);
        $items = $total === 0 ? [] : $this->repository->failedDeliveries($scope, $query->page, $query->perPage);

        return new FailedDeliveriesResult(
            $items,
            $total,
            $query->page,
            $query->perPage,
            $query->from,
            $query->to,
            $sede['nombre'] ?? null,
        );
    }
}
