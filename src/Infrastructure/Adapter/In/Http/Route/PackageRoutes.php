<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Package\GetPackageAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\ListPackagesAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\RegisterPackageAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

return static function (
    App $app,
    RegisterPackageAction $registerPackageAction,
    ListPackagesAction $listPackagesAction,
    GetPackageAction $getPackageAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
    RoleMiddleware $operatorRoleMiddleware,
    MustChangePasswordMiddleware $mustChangePasswordMiddleware,
    RoleMiddleware $operatorOrDriverRoleMiddleware,
): void {
    $notImplemented = static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $response->getBody()->write(json_encode(['message' => 'Endpoint aún no implementado']));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(501);
    };

    $app->post('/api/v1/packages', $registerPackageAction)
        ->add($operatorRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
    $app->get('/api/v1/packages', $listPackagesAction)
        ->add($operatorRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
    $app->get('/api/v1/packages/{id}', $getPackageAction)
        ->add($operatorOrDriverRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
    $app->get('/api/v1/packages/{id}/history', $notImplemented);
};
