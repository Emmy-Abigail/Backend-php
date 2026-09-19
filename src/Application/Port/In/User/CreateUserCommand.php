<?php

declare(strict_types=1);

namespace App\Application\Port\In\User;

final readonly class CreateUserCommand
{
    public function __construct(
        public string $names,
        public string $email,
        public ?string $phone,
        public string $role,
    ) {
    }
}