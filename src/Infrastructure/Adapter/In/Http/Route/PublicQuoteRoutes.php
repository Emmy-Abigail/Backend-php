<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetGeographyAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetPublicSedesAction;
use App\Infrastructure\Adapter\In\Http\Action\Pricing\PublicQuoteAction;
use App\Infrastructure\Adapter\In\Http\Middleware\RateLimitMiddleware;
use Slim\App;

return static function (
    App $app,
    PublicQuoteAction $publicQuoteAction,
    GetPublicSedesAction $getPublicSedesAction,
    GetGeographyAction $getGeographyAction,
    RateLimitMiddleware $publicRateLimitMiddleware,
): void {
    // Sin token: son para el cliente que cotiza desde la web. El límite por IP evita abusos.
    // Las rutas internas (/quotes, /sedes, /geography) siguen protegidas con JWT.
    $app->post('/api/v1/public/quotes', $publicQuoteAction)->add($publicRateLimitMiddleware);
    $app->get('/api/v1/public/sedes', $getPublicSedesAction)->add($publicRateLimitMiddleware);
    $app->get('/api/v1/public/geography', $getGeographyAction)->add($publicRateLimitMiddleware);
};
