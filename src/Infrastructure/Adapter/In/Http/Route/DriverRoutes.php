<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Batch\StartRouteAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use Slim\App;

return static function (
    App $app,
    StartRouteAction $startRouteAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
    RoleMiddleware $driverRoleMiddleware,
    MustChangePasswordMiddleware $mustChangePasswordMiddleware,
): void {
    // Los lotes y paquetes del conductor se consultan con GET /batches y GET /packages/{id}.
    $app->post('/api/v1/batches/{id}/start-route', $startRouteAction)
        ->add($driverRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
};
