<?php

declare(strict_types=1);

namespace App\Application\Port\In\Catalog;

use App\Domain\Catalog\FailureReason;

interface GetFailureReasonsUseCase
{
    /**
     * @return list<FailureReason>
     */
    public function execute(): array;
}