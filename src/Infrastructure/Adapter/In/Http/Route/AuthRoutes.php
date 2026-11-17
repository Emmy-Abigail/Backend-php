<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\Auth\ChangePasswordAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ConfirmPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\GoogleLoginAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\LoginAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\MeAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\RequestPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\SessionStatusAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RateLimitMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\WarmUpServicesMiddleware;
use Slim\App;

return static function (
    App $app,
    LoginAction $loginAction,
    MeAction $meAction,
    SessionStatusAction $sessionStatusAction,
    ChangePasswordAction $changePasswordAction,
    RequestPasswordResetAction $requestPasswordResetAction,
    ConfirmPasswordResetAction $confirmPasswordResetAction,
    JwtAuthMiddleware $jwtAuthMiddleware,
    ?WarmUpServicesMiddleware $warmUpMiddleware = null,
    ?GoogleLoginAction $googleLoginAction = null,
    ?RateLimitMiddleware $loginRateLimitMiddleware = null
): void {
    $loginRoute = $app->post('/api/v1/auth/login', $loginAction);
    $loginRoutes = [$loginRoute];
    if ($googleLoginAction !== null) {
        // Solo existe si GOOGLE_CLIENT_ID esta configurado.
        $loginRoutes[] = $app->post('/api/v1/auth/google', $googleLoginAction);
    }
    if ($warmUpMiddleware !== null) {
        // Despierta a Python mientras el usuario inicia sesion.
        foreach ($loginRoutes as $route) {
            $route->add($warmUpMiddleware);
        }
    }
    if ($loginRateLimitMiddleware !== null) {
        // Se agrega al final para que sea el primero en ejecutarse: un abuso se corta antes de despertar a Python.
        // Solo aplica al login con contrasena; /auth/google no tiene nada que adivinar.
        $loginRoute->add($loginRateLimitMiddleware);
    }
    $app->post('/api/v1/auth/password-reset/request', $requestPasswordResetAction);
    $app->post('/api/v1/auth/password-reset/confirm', $confirmPasswordResetAction);
    $app->get('/api/v1/auth/me', $meAction)->add($jwtAuthMiddleware);
    $app->get('/api/v1/auth/session', $sessionStatusAction)->add($jwtAuthMiddleware);
    $app->patch('/api/v1/auth/change-password', $changePasswordAction)->add($jwtAuthMiddleware);
};
