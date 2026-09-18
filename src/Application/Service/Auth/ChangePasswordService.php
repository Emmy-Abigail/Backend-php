<?php

declare(strict_types=1);

namespace App\Application\Service\Auth;

use App\Application\Exception\InvalidCurrentPassword;
use App\Application\Exception\SamePasswordException;
use App\Application\Exception\UserNotAllowed;
use App\Application\Exception\WeakPasswordException;
use App\Application\Port\In\Auth\ChangePasswordCommand;
use App\Application\Port\In\Auth\ChangePasswordUseCase;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Domain\User\PasswordPolicy;
use RuntimeException;

final readonly class ChangePasswordService implements ChangePasswordUseCase
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    public function execute(ChangePasswordCommand $command): void
    {
        $user = $this->userRepository->findById($command->userId);

        if ($user === null || !$user->active) {
            throw new UserNotAllowed();
        }

        if (!$user->passwordMatches($command->currentPassword)) {
            throw new InvalidCurrentPassword();
        }

        if ($command->currentPassword === $command->newPassword) {
            throw new SamePasswordException();
        }

        if (!PasswordPolicy::isValid($command->newPassword)) {
            throw new WeakPasswordException();
        }

        $newPasswordHash = password_hash($command->newPassword, PASSWORD_DEFAULT);
        if ($newPasswordHash === false) {
            throw new RuntimeException('No fue posible generar el hash de la contraseña');
        }

        $this->userRepository->updatePassword($user->id, $newPasswordHash, false);
    }
}
