<?php

declare(strict_types=1);

namespace App\Application\Port\In\Catalog;

use App\Domain\Catalog\Sede;

interface GetSedesUseCase
{
    /**
     * @return list<Sede>
     */
    public function execute(): array;
}