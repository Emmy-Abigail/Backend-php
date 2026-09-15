<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

final readonly class LoginResult
{
    public function __construct(
        public int $id,
        public string $names,
        public string $email,
        public string $role,
    ) {
    }
}
