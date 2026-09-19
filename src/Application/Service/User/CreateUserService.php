<?php

declare(strict_types=1);

namespace App\Application\Service\User;

use App\Application\Exception\EmailAlreadyExists;
use App\Application\Port\In\User\CreateUserCommand;
use App\Application\Port\In\User\CreateUserResult;
use App\Application\Port\In\User\CreateUserUseCase;
use App\Application\Port\Out\Persistence\UserRepository;

final readonly class CreateUserService implements CreateUserUseCase
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    public function execute(CreateUserCommand $command): CreateUserResult
    {
        if ($this->userRepository->existsByEmail($command->email)) {
            throw new EmailAlreadyExists();
        }

        $temporaryPassword = bin2hex(random_bytes(5));
        $passwordHash = password_hash($temporaryPassword, PASSWORD_BCRYPT);

        $user = $this->userRepository->create(
            $command->names,
            $command->email,
            $command->phone,
            $passwordHash,
            $command->role,
        );

        return new CreateUserResult(
            $user->id,
            $user->names,
            $user->email,
            $user->role,
            $temporaryPassword,
        );
    }
}