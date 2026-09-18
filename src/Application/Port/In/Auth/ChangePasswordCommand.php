<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

final readonly class ChangePasswordCommand
{
    public function __construct(
        public int $userId,
        public string $currentPassword,
        public string $newPassword,
    ) {
    }
}
