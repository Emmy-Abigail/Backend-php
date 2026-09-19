<?php

declare(strict_types=1);

namespace App\Domain\User;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $names,
        public string $email,
        private string $passwordHash,
        public string $role,
        public bool $active,
        public ?string $phone = null,
    ) {
    }

    public function passwordMatches(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
    }
}
