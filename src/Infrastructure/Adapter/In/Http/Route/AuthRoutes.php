<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Auth\ChangePasswordAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\LoginAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\MeAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use Slim\App;

return static function (
    App $app,
    LoginAction $loginAction,
    MeAction $meAction,
    ChangePasswordAction $changePasswordAction,
    JwtAuthMiddleware $jwtAuthMiddleware
): void {
    $app->post('/api/v1/auth/login', $loginAction);
    $app->get('/api/v1/auth/me', $meAction)->add($jwtAuthMiddleware);
    $app->patch('/api/v1/auth/change-password', $changePasswordAction)->add($jwtAuthMiddleware);
};

