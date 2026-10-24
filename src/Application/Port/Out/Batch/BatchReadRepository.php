<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Batch;

use App\Application\Port\In\Batch\BatchPackage;
use App\Application\Port\In\Batch\BatchPage;
use App\Application\Port\In\Batch\BatchSummary;
use App\Application\Port\In\Batch\DriverCandidate;
use App\Domain\Batch\BatchStatus;

interface BatchReadRepository
{
    /**
     * Lotes filtrados por sede o por conductor y por estado. Los lotes en
     * preparación salen del más antiguo al más nuevo; el resto, al revés.
     *
     * @param list<BatchStatus> $statuses
     */
    public function search(?int $sedeId, ?int $driverId, array $statuses, int $page, int $size): BatchPage;

    public function findSummary(int $batchId): ?BatchSummary;

    /**
     * Paquetes del lote: por número de parada si ya está despachado y, si no, por orden de ingreso.
     *
     * @return list<BatchPackage>
     */
    public function packagesOf(int $batchId): array;

    /**
     * Conductores activos con ese tipo de vehículo y menos de 3 lotes pendientes.
     * Primero los que no están en ruta.
     *
     * @return list<DriverCandidate>
     */
    public function availableDrivers(int $vehicleTypeId): array;
}
