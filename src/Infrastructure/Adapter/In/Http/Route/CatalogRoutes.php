<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetFailureReasonsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetGeographyAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetSedesAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetVehicleTypesAction;
use Slim\App;

return static function (
    App $app,
    GetGeographyAction $getGeographyAction,
    GetSedesAction $getSedesAction,
    GetVehicleTypesAction $getVehicleTypesAction,
    GetFailureReasonsAction $getFailureReasonsAction
): void {
    // API v1 routes
    $app->get('/api/v1/geography', $getGeographyAction);
    $app->get('/api/v1/sedes', $getSedesAction);
    $app->get('/api/v1/vehicle-types', $getVehicleTypesAction);
    $app->get('/api/v1/failure-reasons', $getFailureReasonsAction);

    // Direct path aliases
    $app->get('/geography', $getGeographyAction);
    $app->get('/sedes', $getSedesAction);
    $app->get('/vehicle-types', $getVehicleTypesAction);
    $app->get('/failure-reasons', $getFailureReasonsAction);
};