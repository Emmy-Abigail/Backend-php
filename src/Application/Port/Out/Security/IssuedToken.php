<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Security;

use DateTimeImmutable;

final readonly class IssuedToken
{
    public function __construct(
        public string $token,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
