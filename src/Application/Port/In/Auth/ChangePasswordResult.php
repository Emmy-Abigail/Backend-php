<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

use DateTimeImmutable;

final readonly class ChangePasswordResult
{
    public function __construct(
        public string $message,
        public string $token,
        public string $tokenType,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}