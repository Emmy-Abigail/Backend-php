<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Geocoding;

interface DistrictDirectory
{
    /** @return list<array{ubigeo: string, nombre_oficial: string, nombre_mostrado: string}> distritos activos con cobertura */
    public function activeDistricts(): array;
}
