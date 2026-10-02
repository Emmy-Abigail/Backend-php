<?php

declare(strict_types=1);

namespace App\Application\Port\In\User;

interface ListUsersUseCase
{
    public function execute(?string $role = null, ?int $idSede = null): ListUsersResult;
}