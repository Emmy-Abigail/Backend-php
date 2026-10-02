<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Catalog;

use App\Domain\Catalog\Department;
use App\Domain\Catalog\FailureReason;
use App\Domain\Catalog\Sede;
use App\Domain\Catalog\VehicleType;

interface CatalogRepository
{
    /**
     * @return list<Department>
     */
    public function getGeography(): array;

    /**
     * @return list<Sede>
     */
    public function getSedes(): array;

    /**
     * @return list<VehicleType>
     */
    public function getVehicleTypes(): array;

    /**
     * @return list<FailureReason>
     */
    public function getFailureReasons(): array;
}