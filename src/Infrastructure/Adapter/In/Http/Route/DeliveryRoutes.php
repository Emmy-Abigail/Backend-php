<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use App\Infrastructure\Adapter\In\Http\Action\Delivery\FinalStatusAction;
use App\Infrastructure\Adapter\In\Http\Action\Delivery\GetEvidenceAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\PrivateEvidenceMiddleware;

return static function (
    App $app,
    FinalStatusAction $finalStatusAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
    RoleMiddleware $driverRoleMiddleware,
    MustChangePasswordMiddleware $mustChangePasswordMiddleware,
    GetEvidenceAction $getEvidenceAction,
    RoleMiddleware $evidenceRoleMiddleware,
): void {
    $notImplemented = static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $response->getBody()->write(json_encode(['message' => 'Endpoint aún no implementado']));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(501);
    };

    $app->post('/api/v1/packages/{id}/final-status', $finalStatusAction)
        ->add($driverRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);

    $app->post('/api/v1/packages/{id}/deliver', $notImplemented);
    $app->map(['GET', 'HEAD'], '/api/v1/packages/{id}/evidence', $getEvidenceAction)
        ->add($evidenceRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware)
        ->add(new PrivateEvidenceMiddleware());
    $app->post('/api/v1/packages/{id}/fail', $notImplemented);
};
