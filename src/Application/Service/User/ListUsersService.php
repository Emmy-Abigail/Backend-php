<?php

declare(strict_types=1);

namespace App\Application\Service\User;

use App\Application\Port\In\User\ListUsersResult;
use App\Application\Port\In\User\ListUsersUseCase;
use App\Application\Port\In\User\UserListItem;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Domain\User\User;

final readonly class ListUsersService implements ListUsersUseCase
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    public function execute(): ListUsersResult
    {
        $users = array_map(
            static fn (User $user): UserListItem => new UserListItem(
                $user->id,
                $user->names,
                $user->email,
                $user->phone,
                $user->role,
                $user->active,
            ),
            $this->userRepository->findAll(),
        );

        return new ListUsersResult($users);
    }
}
