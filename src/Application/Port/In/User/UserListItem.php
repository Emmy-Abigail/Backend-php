<?php

declare(strict_types=1);

namespace App\Application\Port\In\User;

final readonly class UserListItem
{
    public function __construct(
        public int $id,
        public string $names,
        public string $email,
        public string $dni,
        public ?string $phone,
        public string $role,
        public bool $active,
        public ?int $idSede = null,
        public ?int $idTipoVehiculo = null,
        public ?string $sedeNombre = null,
        public ?string $tipoVehiculo = null,
    ) {
    }
}