<?php

declare(strict_types=1);

namespace App\Application\Port\In\User;

final readonly class ChangeUserStatusCommand
{
    public function __construct(
        public int $userId,
        public bool $active,
    ) {
    }
}