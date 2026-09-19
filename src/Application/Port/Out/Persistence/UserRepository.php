<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Persistence;

use App\Domain\User\User;

interface UserRepository
{
    public function findByEmail(string $email): ?User;

    public function findById(int $id): ?User;

    /**
     * @return list<User>
     */
    public function findAll(): array;

    public function existsByEmail(string $email): bool;

    public function create(
        string $names,
        string $email,
        ?string $phone,
        string $passwordHash,
        string $role,
    ): User;

    public function updatePassword(int $userId, string $newPasswordHash, bool $mustChangePassword = false): void;
}
