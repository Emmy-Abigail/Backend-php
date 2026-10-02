<?php

declare(strict_types=1);

namespace App\Application\Port\In\Catalog;

use App\Domain\Catalog\Department;

interface GetGeographyUseCase
{
    /**
     * @return list<Department>
     */
    public function execute(): array;
}