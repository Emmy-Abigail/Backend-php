<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Package\GetPackageAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\ListPackagesAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\RegisterPackageAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\RetryPackageAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use Slim\App;

return static function (
    App $app,
    RegisterPackageAction $registerPackageAction,
    ListPackagesAction $listPackagesAction,
    GetPackageAction $getPackageAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
    RoleMiddleware $operatorRoleMiddleware,
    MustChangePasswordMiddleware $mustChangePasswordMiddleware,
    RetryPackageAction $retryPackageAction,
    RoleMiddleware $readerRoleMiddleware,
): void {
    // El historial del paquete viene dentro de GET /packages/{id}.
    $app->post('/api/v1/packages', $registerPackageAction)
        ->add($operatorRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
    // Consulta: el middleware deja pasar a los tres roles y el servicio aplica los permisos finos
    // (el conductor no lista, el operador solo ve su sede, el administrador ve todas).
    $app->get('/api/v1/packages', $listPackagesAction)
        ->add($readerRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
    $app->get('/api/v1/packages/{id}', $getPackageAction)
        ->add($readerRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
    $app->post('/api/v1/packages/{id}/retry', $retryPackageAction)
        ->add($operatorRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
};
