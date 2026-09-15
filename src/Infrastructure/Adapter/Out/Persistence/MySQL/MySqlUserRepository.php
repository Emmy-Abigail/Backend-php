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

        return new User(
            (int) $record->getAttribute('id'),
            (string) $record->getAttribute('nombres'),
            (string) $record->getAttribute('correo'),
            (string) $record->getAttribute('password_hash'),
            (string) $record->getAttribute('rol'),
            (bool) $record->getAttribute('activo'),
        );
    }
}
