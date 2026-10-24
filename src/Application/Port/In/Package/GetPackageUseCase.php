<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

interface GetPackageUseCase
{
    public function execute(GetPackageQuery $query): GetPackageResult;
}
