<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\User\CreateUserAction;
use App\Infrastructure\Adapter\In\Http\Action\User\ListUsersAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use Slim\App;

return static function (App $app, CreateUserAction $createUserAction, ListUsersAction $listUsersAction, JwtAuthMiddleware $jwtAuthMiddleware, RoleMiddleware $adminRoleMiddleware,): void {
    $app->post('/api/v1/users', $createUserAction)
        ->add($adminRoleMiddleware)
        ->add($jwtAuthMiddleware);

    $app->get('/api/v1/users', $listUsersAction)
        ->add($adminRoleMiddleware)
        ->add($jwtAuthMiddleware);
};