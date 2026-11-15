<?php

declare(strict_types=1);

namespace App\Application\Port\In\Dashboard;

interface GetDashboardMetricsUseCase
{
    public function execute(GetDashboardMetricsQuery $query): DashboardMetricsResult;
}
