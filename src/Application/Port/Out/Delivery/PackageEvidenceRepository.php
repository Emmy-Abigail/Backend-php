<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Delivery;

interface PackageEvidenceRepository
{
    public function find(int $packageId): ?PackageEvidenceReference;
}
