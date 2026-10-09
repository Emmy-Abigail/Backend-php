<?php

declare(strict_types=1);

namespace App\Application\Service\User;

use App\Application\Port\In\User\ListUsersResult;
use App\Application\Port\In\User\ListUsersUseCase;
use App\Application\Port\In\User\UserListItem;
use App\Application\Port\Out\Persistence\UserRepository;

final readonly class ListUsersService implements ListUsersUseCase
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    public function execute(?string $role = null, ?int $idSede = null): ListUsersResult
    {
        $rawUsers = $this->userRepository->findAllWithDetails($role, $idSede);

        $users = array_map(
            static fn (array $u): UserListItem => new UserListItem(
                $u['id'],
                $u['nombres'],
                $u['correo'],
                $u['dni'],
                $u['telefono'],
                $u['rol'],
                $u['activo'],
                $u['id_sede'],
                $u['id_tipo_vehiculo'],
                $u['sede_nombre'],
                $u['tipo_vehiculo'],
                $u['placa'] ?? null,
            ),
            $rawUsers,
        );

        return new ListUsersResult($users);
    }
}