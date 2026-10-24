<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Batch\DispatchBatchAction;
use App\Infrastructure\Adapter\In\Http\Action\Batch\GetBatchAction;
use App\Infrastructure\Adapter\In\Http\Action\Batch\ListBatchesAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
    $notImplemented = static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $response->getBody()->write(json_encode(['message' => 'Endpoint aún no implementado']));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(501);
    };

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

    // Sin cambios: el lote ahora se crea solo al registrar el paquete y el conductor se asigna al despachar.
    $app->post('/api/v1/batches', $notImplemented);
    $app->patch('/api/v1/batches/{id}/assignment', $notImplemented);
};
