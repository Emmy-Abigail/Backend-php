<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Security;

final readonly class TokenClaims
{
    public function __construct(
        public int $userId,
        public string $role,
    ) {
    }
}
