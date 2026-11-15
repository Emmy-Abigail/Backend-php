<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Dashboard\GetDashboardMetricsAction;
use App\Infrastructure\Adapter\In\Http\Action\Dashboard\ListFailedDeliveriesAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use Slim\App;

return static function (
    App $app,
    GetDashboardMetricsAction $getDashboardMetricsAction,
    ListFailedDeliveriesAction $listFailedDeliveriesAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
    RoleMiddleware $adminOrOperatorRoleMiddleware,
    MustChangePasswordMiddleware $mustChangePasswordMiddleware,
): void {
    // Administrador: todas las sedes o la elegida. Operador: siempre su sede (la decide el servicio).
    $app->get('/api/v1/dashboard/metrics', $getDashboardMetricsAction)
        ->add($adminOrOperatorRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
    $app->get('/api/v1/dashboard/failed-deliveries', $listFailedDeliveriesAction)
        ->add($adminOrOperatorRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
};
