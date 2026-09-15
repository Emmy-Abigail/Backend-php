<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

interface LoginUseCase
{
    public function execute(LoginCommand $command): LoginResult;
}
