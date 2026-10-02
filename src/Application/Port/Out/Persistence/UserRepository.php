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
    public function findAll(?string $role = null, ?int $idSede = null): array;

    public function existsByEmail(string $email): bool;

    public function existsByDni(string $dni): bool;

    public function create(
        string $names,
        string $dni,
        string $email,
        ?string $phone,
        string $passwordHash,
        string $role,
        ?int $idSede,
        ?int $idTipoVehiculo,
    ): User;

    public function deleteById(int $userId): void;

    public function updatePassword(int $userId, string $newPasswordHash, bool $mustChangePassword = false): void;

    public function updateStatus(int $userId, bool $active): void;
}