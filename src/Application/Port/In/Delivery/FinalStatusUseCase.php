<?php

declare(strict_types=1);

namespace App\Application\Port\In\Delivery;

interface FinalStatusUseCase
{
    public function execute(FinalStatusCommand $command): FinalStatusResult;
}
