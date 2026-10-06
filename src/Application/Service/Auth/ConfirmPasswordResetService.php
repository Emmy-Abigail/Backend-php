<?php

declare(strict_types=1);

namespace App\Application\Service\Auth;

use App\Application\Exception\InvalidPasswordResetToken;
use App\Application\Exception\SamePasswordException;
use App\Application\Exception\WeakPasswordException;
use App\Application\Port\In\Auth\ConfirmPasswordResetCommand;
use App\Application\Port\In\Auth\ConfirmPasswordResetUseCase;
use App\Application\Port\Out\Persistence\PasswordResetTokenRepository;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Domain\User\PasswordPolicy;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;

final readonly class ConfirmPasswordResetService implements ConfirmPasswordResetUseCase
{
    public function __construct(
        private UserRepository $userRepository,
        private PasswordResetTokenRepository $passwordResetTokenRepository,
    ) {
    }

    public function execute(ConfirmPasswordResetCommand $command): void
    {
        $now = new DateTimeImmutable();
        $resetToken = $this->passwordResetTokenRepository->findUsableByHash(hash('sha256', $command->token), $now);
        if ($resetToken === null) {
            throw new InvalidPasswordResetToken('El enlace no es válido o expiró');
        }

        $user = $this->userRepository->findById($resetToken->userId);
        if ($user === null || !$user->active) {
            $this->passwordResetTokenRepository->markAsUsed($resetToken->id, $now);

            throw new InvalidPasswordResetToken('El enlace no es válido o expiró');
        }

        if (!PasswordPolicy::isValid($command->newPassword)) {
            throw new WeakPasswordException();
        }

        if ($user->passwordMatches($command->newPassword)) {
            throw new SamePasswordException();
        }

        $passwordHash = password_hash($command->newPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('No fue posible generar el hash de la contraseña');
        }

        Capsule::transaction(function () use ($user, $passwordHash, $resetToken, $now): void {
            $this->userRepository->updatePassword($user->id, $passwordHash, false);
            $this->passwordResetTokenRepository->markAsUsed($resetToken->id, $now);
        });
    }
}