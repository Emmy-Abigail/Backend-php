<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

use DateTimeImmutable;

final readonly class LoginResult
{
    public function __construct(
        public int $id,
        public string $names,
        public string $email,
        public string $role,
        public string $token,
        public string $tokenType,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
