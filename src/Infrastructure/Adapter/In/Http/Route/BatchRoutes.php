<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Batch\DispatchBatchAction;
use App\Infrastructure\Adapter\In\Http\Action\Batch\GetBatchAction;
use App\Infrastructure\Adapter\In\Http\Action\Batch\ListBatchesAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use Slim\App;

return static function (
    App $app,
    ListBatchesAction $listBatchesAction,
    GetBatchAction $getBatchAction,
    DispatchBatchAction $dispatchBatchAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
    RoleMiddleware $operatorOrDriverRoleMiddleware,
    RoleMiddleware $operatorRoleMiddleware,
    MustChangePasswordMiddleware $mustChangePasswordMiddleware,
): void {
    // El lote se crea solo al registrar el paquete y el conductor se asigna al despachar:
    // no hay rutas para crear ni asignar lotes a mano.
    $app->get('/api/v1/batches', $listBatchesAction)
        ->add($operatorOrDriverRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
    $app->get('/api/v1/batches/{id}', $getBatchAction)
        ->add($operatorOrDriverRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
    $app->post('/api/v1/batches/{id}/dispatch', $dispatchBatchAction)
        ->add($operatorRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
};
