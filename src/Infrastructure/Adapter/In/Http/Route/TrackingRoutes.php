<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Tracking\GetTrackingAction;
use App\Infrastructure\Adapter\In\Http\Middleware\RateLimitMiddleware;
use Slim\App;

return static function (
    App $app,
    GetTrackingAction $getTrackingAction,
    RateLimitMiddleware $trackingRateLimitMiddleware,
): void {
    // Público (sin token) para el cliente final; el límite de consultas evita que se adivinen códigos.
    $app->get('/api/v1/tracking/{trackingId}', $getTrackingAction)
        ->add($trackingRateLimitMiddleware);
};
