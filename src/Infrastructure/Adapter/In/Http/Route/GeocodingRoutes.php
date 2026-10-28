<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Geocoding\ReverseGeocodeAction;
use App\Infrastructure\Adapter\In\Http\Action\Geocoding\SuggestAddressesAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use Slim\App;

return static function (
    App $app,
    SuggestAddressesAction $suggestAddressesAction,
    ReverseGeocodeAction $reverseGeocodeAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
    RoleMiddleware $operatorRoleMiddleware,
    MustChangePasswordMiddleware $mustChangePasswordMiddleware,
): void {
    // Solo el operador las usa, al registrar la dirección de entrega.
    $app->get('/api/v1/geocode/suggest', $suggestAddressesAction)
        ->add($operatorRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
    $app->get('/api/v1/geocode/reverse', $reverseGeocodeAction)
        ->add($operatorRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
};
