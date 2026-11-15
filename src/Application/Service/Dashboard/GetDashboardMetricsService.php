<?php

declare(strict_types=1);

namespace App\Application\Service\Dashboard;

use App\Application\Port\In\Dashboard\DashboardMetricsResult;
use App\Application\Port\In\Dashboard\GetDashboardMetricsQuery;
use App\Application\Port\In\Dashboard\GetDashboardMetricsUseCase;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Dashboard\DashboardReadRepository;
use App\Application\Port\Out\Dashboard\DashboardScope;

/** Métricas del dashboard (Demo 4) en una sola respuesta, con el alcance que permite cada rol. */
final readonly class GetDashboardMetricsService implements GetDashboardMetricsUseCase
{
    private const LATEST_FAILURES = 3;

    public function __construct(
        private DashboardReadRepository $repository,
        private CatalogRepository $catalogs,
        private DashboardAccess $access,
        private DashboardMetricsAssembler $assembler = new DashboardMetricsAssembler(),
    ) {
    }

    public function execute(GetDashboardMetricsQuery $query): DashboardMetricsResult
    {
        $user = $query->user;
        $sede = $this->access->sedeFor($user, $query->sedeId);
        $scope = new DashboardScope($query->from, $query->to, $sede['id'] ?? null);
        $isAdmin = $user->role === 'ADMINISTRADOR';

        return new DashboardMetricsResult($this->assembler->assemble(
            role: $user->role,
            userId: $user->id,
            sede: $sede,
            registrations: $this->repository->registrationGroups($scope),
            events: $this->repository->terminalEvents($scope),
            dispatched: $this->repository->dispatchedPackages($scope),
            operators: $isAdmin ? $this->repository->operators($scope->sedeId) : [],
            latestFailures: $this->repository->failedDeliveries($scope, 1, self::LATEST_FAILURES),
            sedes: $isAdmin && $sede === null ? $this->catalogs->getSedes() : [],
            failureReasons: $this->catalogs->getFailureReasons(),
            vehicleTypes: $this->catalogs->getVehicleTypes(),
        ));
    }
}
