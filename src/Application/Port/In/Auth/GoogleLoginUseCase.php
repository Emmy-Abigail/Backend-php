<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

interface GoogleLoginUseCase
{
    public function execute(GoogleLoginCommand $command): LoginResult;
}
