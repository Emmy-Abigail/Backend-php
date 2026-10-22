<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Pricing\QuoteAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use Slim\App;

return static function (
    App $app,
    QuoteAction $quoteAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
    RoleMiddleware $operatorRoleMiddleware,
    MustChangePasswordMiddleware $mustChangePasswordMiddleware,
): void {
    $app->post('/api/v1/quotes', $quoteAction)
        ->add($operatorRoleMiddleware)
        ->add($mustChangePasswordMiddleware)
        ->add($jwtAuthMiddleware);
};
