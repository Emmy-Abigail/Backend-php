<?php

declare(strict_types=1);

namespace App\Application\Service\User;

use App\Application\Exception\EmailAlreadyExists;
use App\Application\Port\In\User\CreateUserCommand;
use App\Application\Port\In\User\CreateUserResult;
use App\Application\Port\In\User\CreateUserUseCase;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Domain\User\PasswordPolicy;
use RuntimeException;

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

        $temporaryPassword = PasswordPolicy::generateTemporaryPassword();
        $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            throw new RuntimeException('No fue posible generar el hash de la contraseña temporal');
        }

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