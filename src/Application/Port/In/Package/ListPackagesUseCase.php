<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

interface ListPackagesUseCase
{
    public function execute(ListPackagesQuery $query): ListPackagesResult;
}
