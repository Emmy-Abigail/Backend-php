<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Persistence;

interface PackageRetrySourceRepository
{
    public function findRetrySource(int $packageId): ?PackageRetrySource;
}
