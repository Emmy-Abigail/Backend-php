<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

interface ListBatchesUseCase
{
    public function execute(ListBatchesQuery $query): BatchPage;
}
