<?php

declare(strict_types=1);

namespace App\Application\Port\In\Dashboard;

interface ListFailedDeliveriesUseCase
{
    public function execute(ListFailedDeliveriesQuery $query): FailedDeliveriesResult;
}
