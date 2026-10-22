<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetCatalogsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetFailureReasonsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetGeographyAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetSedesAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetVehicleTypesAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use Slim\App;

return static function (
    App $app,
    GetGeographyAction $getGeographyAction,
    GetSedesAction $getSedesAction,
    GetVehicleTypesAction $getVehicleTypesAction,
    GetFailureReasonsAction $getFailureReasonsAction,
    GetCatalogsAction $getCatalogsAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
): void {
    // Catálogos disponibles para cualquier usuario autenticado.
    $app->get('/api/v1/geography', $getGeographyAction)->add($jwtAuthMiddleware);
    $app->get('/api/v1/sedes', $getSedesAction)->add($jwtAuthMiddleware);
    $app->get('/api/v1/vehicle-types', $getVehicleTypesAction)->add($jwtAuthMiddleware);
    $app->get('/api/v1/failure-reasons', $getFailureReasonsAction)->add($jwtAuthMiddleware);
    $app->get('/api/v1/catalogs', $getCatalogsAction)->add($jwtAuthMiddleware);

    // Los alias conservan la misma protección que las rutas versionadas.
    $app->get('/geography', $getGeographyAction)->add($jwtAuthMiddleware);
    $app->get('/sedes', $getSedesAction)->add($jwtAuthMiddleware);
    $app->get('/vehicle-types', $getVehicleTypesAction)->add($jwtAuthMiddleware);
    $app->get('/failure-reasons', $getFailureReasonsAction)->add($jwtAuthMiddleware);
    $app->get('/catalogs', $getCatalogsAction)->add($jwtAuthMiddleware);
};
