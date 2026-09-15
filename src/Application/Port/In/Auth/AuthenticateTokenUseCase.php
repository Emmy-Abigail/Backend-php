<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

interface AuthenticateTokenUseCase
{
    public function execute(string $token): AuthenticatedUser;
}
