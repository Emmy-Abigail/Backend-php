<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Auth\ChangePasswordAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ConfirmPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\LoginAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\MeAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\RequestPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use Slim\App;

return static function (
    App $app,
    LoginAction $loginAction,
    MeAction $meAction,
    ChangePasswordAction $changePasswordAction,
    RequestPasswordResetAction $requestPasswordResetAction,
    ConfirmPasswordResetAction $confirmPasswordResetAction,
    JwtAuthMiddleware $jwtAuthMiddleware
): void {
    $app->post('/api/v1/auth/login', $loginAction);
    $app->post('/api/v1/auth/password-reset/request', $requestPasswordResetAction);
    $app->post('/api/v1/auth/password-reset/confirm', $confirmPasswordResetAction);
    $app->get('/api/v1/auth/me', $meAction)->add($jwtAuthMiddleware);
    $app->patch('/api/v1/auth/change-password', $changePasswordAction)->add($jwtAuthMiddleware);
};
