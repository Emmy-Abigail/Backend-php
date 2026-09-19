<?php

declare(strict_types=1);

namespace App\Application\Port\In\User;

interface CreateUserUseCase
{
    public function execute(CreateUserCommand $command): CreateUserResult;
}