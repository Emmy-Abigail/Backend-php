<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

interface StartRouteUseCase
{
    public function execute(StartRouteCommand $command): StartRouteResult;
}
