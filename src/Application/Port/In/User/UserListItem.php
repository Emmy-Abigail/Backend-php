<?php

declare(strict_types=1);

namespace App\Application\Port\In\User;

final readonly class UserListItem
{
    public function __construct(
        public int $id,
        public string $names,
        public string $email,
        public ?string $phone,
        public string $role,
        public bool $active,
    ) {
    }
}
