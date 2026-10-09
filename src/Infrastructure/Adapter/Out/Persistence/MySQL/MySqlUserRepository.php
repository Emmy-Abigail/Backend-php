<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Exception\DniAlreadyExists;
use App\Application\Exception\EmailAlreadyExists;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
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

    /**
     * @return list<User>
     */
    public function findAll(?string $role = null, ?int $idSede = null): array
    {
        $query = UserRecord::query()->orderBy('id');

        if ($role !== null) {
            $query->where('rol', $role);
        }

        if ($idSede !== null) {
            $query->where('id_sede', $idSede);
        }

        $users = [];

        foreach ($query->get() as $record) {
            $users[] = $this->toDomain($record);
        }

        return $users;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllWithDetails(?string $role = null, ?int $idSede = null): array
    {
        $query = UserRecord::query()
            ->leftJoin('sedes', 'usuarios.id_sede', '=', 'sedes.id')
            ->leftJoin('tipos_vehiculo', 'usuarios.id_tipo_vehiculo', '=', 'tipos_vehiculo.id')
            ->select([
                'usuarios.id',
                'usuarios.nombres',
                'usuarios.correo',
                'usuarios.dni',
                'usuarios.telefono',
                'usuarios.rol',
                'usuarios.activo',
                'usuarios.id_sede',
                'usuarios.id_tipo_vehiculo',
                'sedes.nombre as sede_nombre',
                'tipos_vehiculo.nombre as tipo_vehiculo_nombre',
            ])
            ->orderBy('usuarios.id');

        if ($role !== null) {
            $query->where('usuarios.rol', $role);
        }

        if ($idSede !== null) {
            $query->where('usuarios.id_sede', $idSede);
        }

        $results = [];
        foreach ($query->get() as $row) {
            $results[] = [
                'id' => (int) $row->id,
                'nombres' => (string) $row->nombres,
                'correo' => (string) $row->correo,
                'dni' => (string) ($row->dni ?? ''),
                'telefono' => $row->telefono !== null ? (string) $row->telefono : null,
                'rol' => (string) $row->rol,
                'activo' => (bool) $row->activo,
                'id_sede' => $row->id_sede !== null ? (int) $row->id_sede : null,
                'id_tipo_vehiculo' => $row->id_tipo_vehiculo !== null ? (int) $row->id_tipo_vehiculo : null,
                'sede_nombre' => $row->sede_nombre !== null ? (string) $row->sede_nombre : null,
                'tipo_vehiculo' => $row->tipo_vehiculo_nombre !== null ? (string) $row->tipo_vehiculo_nombre : null,
            ];
        }

        return $results;
    }

    public function existsByEmail(string $email): bool
    {
        return UserRecord::query()->where('correo', $email)->exists();
    }

    public function existsByDni(string $dni): bool
    {
        return UserRecord::query()->where('dni', $dni)->exists();
    }

    public function create(
        string $names,
        string $dni,
        string $email,
        ?string $phone,
        string $passwordHash,
        string $role,
        ?int $idSede,
        ?int $idTipoVehiculo,
    ): User {
        try {
            $record = UserRecord::query()->create([
                'nombres' => $names,
                'dni' => $dni,
                'correo' => $email,
                'telefono' => $phone,
                'password_hash' => $passwordHash,
                'rol' => $role,
                'id_sede' => $idSede,
                'id_tipo_vehiculo' => $idTipoVehiculo,
                'debe_cambiar_password' => true,
                'activo' => true,
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000' && str_contains($exception->getMessage(), 'uq_usuarios_correo')) {
                throw new EmailAlreadyExists('Ya existe un usuario con ese correo', 0, $exception);
            }

            if ($exception->getCode() === '23000' && str_contains($exception->getMessage(), 'uq_usuarios_dni')) {
                throw new DniAlreadyExists('Ya existe un usuario con ese DNI', 0, $exception);
            }

            throw $exception;
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
                'password_changed_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function updateStatus(int $userId, bool $active): void
    {
        UserRecord::query()
            ->where('id', $userId)
            ->update(['activo' => $active]);
    }

    public function deleteById(int $userId): void
    {
        UserRecord::query()->where('id', $userId)->delete();
    }

    private function toDomain(UserRecord $record): User
    {
        $passwordChangedAt = null;
        $rawChangedAt = $record->getAttribute('password_changed_at');
        if ($rawChangedAt !== null && is_string($rawChangedAt) && $rawChangedAt !== '') {
            $passwordChangedAt = new DateTimeImmutable($rawChangedAt, new DateTimeZone('UTC'));
        }

        return new User(
            (int) $record->getAttribute('id'),
            (string) $record->getAttribute('nombres'),
            (string) $record->getAttribute('correo'),
            (string) $record->getAttribute('password_hash'),
            (string) $record->getAttribute('rol'),
            (bool) $record->getAttribute('activo'),
            $record->getAttribute('telefono') === null ? null : (string) $record->getAttribute('telefono'),
            (bool) $record->getAttribute('debe_cambiar_password'),
            (string) ($record->getAttribute('dni') ?? ''),
            $record->getAttribute('id_sede') === null ? null : (int) $record->getAttribute('id_sede'),
            $record->getAttribute('id_tipo_vehiculo') === null ? null : (int) $record->getAttribute('id_tipo_vehiculo'),
            $passwordChangedAt,
        );
    }
}