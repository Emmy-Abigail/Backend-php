<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\Out\Persistence\UserRepository;
use App\Domain\User\User;

final class MySqlUserRepository implements UserRepository
{
    public function findByEmail(string $email): ?User
    {
        $record = UserRecord::query()
            ->where('correo', $email)
            ->first();

        if ($record === null) {
            return null;
        }

        return $this->toDomain($record);
    }

    public function findById(int $id): ?User
    {
        $record = UserRecord::query()->find($id);

        if ($record === null) {
            return null;
        }

        return $this->toDomain($record);
    }

    public function updatePassword(int $userId, string $newPasswordHash, bool $mustChangePassword = false): void
    {
        UserRecord::query()
            ->where('id', $userId)
            ->update([
                'password_hash' => $newPasswordHash,
                'debe_cambiar_password' => $mustChangePassword,
            ]);
    }

    private function toDomain(UserRecord $record): User
    {
        return new User(
            (int) $record->getAttribute('id'),
            (string) $record->getAttribute('nombres'),
            (string) $record->getAttribute('correo'),
            (string) $record->getAttribute('password_hash'),
            (string) $record->getAttribute('rol'),
            (bool) $record->getAttribute('activo'),
            (bool) $record->getAttribute('debe_cambiar_password'),
        );
    }
}
