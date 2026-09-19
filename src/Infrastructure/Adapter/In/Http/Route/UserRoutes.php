<?php

declare(strict_types=1);

use App\Infrastructure\Adapter\In\Http\Action\User\CreateUserAction;
use Slim\App;

return static function (App $app, CreateUserAction $createUserAction): void {
    $app->post('/api/v1/users', $createUserAction);
};