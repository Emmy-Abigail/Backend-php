<?php

declare(strict_types=1);

namespace App\Application\Port\In\User;

final readonly class CreateUserResult
{
    public function __construct(
        public int $id,
        public string $names,
        public string $dni,
        public string $email,
        public string $role,
        public ?int $idSede,
        public ?int $idTipoVehiculo,
        public string $temporaryPassword,
        public bool $emailSent = true,
        public ?string $placa = null,
    ) {
    }
}