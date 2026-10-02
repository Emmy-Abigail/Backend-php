<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use DateTimeImmutable;

final readonly class PasswordResetToken
{
    public function __construct(
        public int $id,
        public int $userId,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt,
    ) {
    }
}
