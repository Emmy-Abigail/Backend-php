<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Exception\EmailAlreadyExists;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Domain\User\User;
use Illuminate\Database\QueryException;

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

    public function existsByEmail(string $email): bool
    {
        return UserRecord::query()->where('correo', $email)->exists();
    }

    public function create(
        string $names,
        string $email,
        ?string $phone,
        string $passwordHash,
        string $role,
    ): User {
        try {
            $record = UserRecord::query()->create([
                'nombres' => $names,
                'correo' => $email,
                'telefono' => $phone,
                'password_hash' => $passwordHash,
                'rol' => $role,
                'debe_cambiar_password' => true,
                'activo' => true,
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000' && str_contains($exception->getMessage(), 'uq_usuarios_correo')) {
                throw new EmailAlreadyExists('Ya existe un usuario con ese correo', 0, $exception);
            }

            throw $exception;
        }

        return $this->toDomain($record);
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
        );
    }
}