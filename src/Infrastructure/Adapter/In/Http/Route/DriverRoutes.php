<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Batch\StartRouteAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

return static function (
    App $app,
    StartRouteAction $startRouteAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
    RoleMiddleware $driverRoleMiddleware,
    MustChangePasswordMiddleware $mustChangePasswordMiddleware,
): void {
    $notImplemented = static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $response->getBody()->write(json_encode(['message' => 'Endpoint aún no implementado']));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(501);
    };

    $app->get('/api/v1/me/batches', $notImplemented);
    $app->get('/api/v1/me/packages', $notImplemented);
    $app->post('/api/v1/batches/{id}/start-route', $startRouteAction)
        ->add($driverRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
};
