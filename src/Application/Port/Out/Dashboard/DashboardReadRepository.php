<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Dashboard;

/**
 * Lectura de los hechos que alimentan el dashboard. Solo consulta: no modifica datos.
 * Todas las fechas devueltas están en UTC; los cálculos se hacen en la capa de aplicación.
 */
interface DashboardReadRepository
{
    /** @return list<RegistrationGroup> */
    public function registrationGroups(DashboardScope $scope): array;

    /** @return list<TerminalEvent> */
    public function terminalEvents(DashboardScope $scope): array;

    /** @return list<DispatchedPackage> */
    public function dispatchedPackages(DashboardScope $scope): array;

    /** @return list<OperatorReference> */
    public function operators(?int $sedeId): array;

    /**
     * Fallidos del período, del más reciente al más antiguo.
     *
     * @return list<FailedDelivery>
     */
    public function failedDeliveries(DashboardScope $scope, int $page, int $perPage): array;

    public function countFailedDeliveries(DashboardScope $scope): int;
}
