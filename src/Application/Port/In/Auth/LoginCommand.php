<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

final readonly class LoginCommand
{
    public function __construct(
        public string $email,
        public string $password,
    ) {
    }
}
