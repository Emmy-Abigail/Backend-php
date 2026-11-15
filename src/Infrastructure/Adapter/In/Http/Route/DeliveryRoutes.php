<?php

declare(strict_types=1);

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
    // Entregado y Fallido se registran por la misma ruta de estado final.
    $app->post('/api/v1/packages/{id}/final-status', $finalStatusAction)
        ->add($driverRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);

    $app->map(['GET', 'HEAD'], '/api/v1/packages/{id}/evidence', $getEvidenceAction)
        ->add($evidenceRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware)
        ->add(new PrivateEvidenceMiddleware());
};
