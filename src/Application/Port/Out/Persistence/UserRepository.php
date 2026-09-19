<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Persistence;

use App\Domain\User\User;

interface UserRepository
{
    public function findByEmail(string $email): ?User;

    public function findById(int $id): ?User;

    public function updatePassword(int $userId, string $newPasswordHash, bool $mustChangePassword = false): void;
}
