<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Security;

use App\Domain\User\User;

interface TokenService
{
    public function issue(User $user): IssuedToken;

    public function verify(string $token): TokenClaims;
}
