<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

interface GetBatchUseCase
{
    public function execute(GetBatchQuery $query): GetBatchResult;
}
