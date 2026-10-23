<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

interface DispatchBatchUseCase
{
    public function execute(DispatchBatchCommand $command): DispatchBatchResult;
}
