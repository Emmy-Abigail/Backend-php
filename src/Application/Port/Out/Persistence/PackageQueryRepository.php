<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Persistence;

use App\Application\Port\In\Package\ListPackagesResult;

interface PackageQueryRepository
{
    public function searchBySede(
        int $sedeId,
        ?string $search,
        ?string $status,
        ?string $from,
        ?string $to,
        int $page,
        int $perPage,
    ): ListPackagesResult;

    public function findDetail(int $packageId): ?PackageDetail;

    /**
     * Vista pública del seguimiento: estado, distrito y línea de tiempo, sin datos personales.
     *
     * @return array<string, mixed>|null
     */
    public function findPublicTracking(string $trackingId): ?array;
}
