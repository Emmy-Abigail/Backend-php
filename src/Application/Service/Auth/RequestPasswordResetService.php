<?php

declare(strict_types=1);

namespace App\Application\Service\Auth;

use App\Application\Exception\EmailDeliveryFailed;
use App\Application\Port\In\Auth\RequestPasswordResetCommand;
use App\Application\Port\In\Auth\RequestPasswordResetUseCase;
use App\Application\Port\Out\Notification\PasswordResetMailer;
use App\Application\Port\Out\Persistence\PasswordResetTokenRepository;
use App\Application\Port\Out\Persistence\UserRepository;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RequestPasswordResetService implements RequestPasswordResetUseCase
{
    public function __construct(
        private UserRepository $userRepository,
        private PasswordResetTokenRepository $passwordResetTokenRepository,
        private PasswordResetMailer $passwordResetMailer,
        private int $tokenTtlSeconds = 1800,
    ) {
        if ($this->tokenTtlSeconds < 60 || $this->tokenTtlSeconds > 86400) {
            throw new InvalidArgumentException('La vigencia del token debe estar entre 60 segundos y 24 horas');
        }
    }

    public function execute(RequestPasswordResetCommand $command): void
    {
        $user = $this->userRepository->findByEmail($command->email);
        if ($user === null || !$user->active) {
            return;
        }

        $now = new DateTimeImmutable();
        $token = bin2hex(random_bytes(32));
        $record = $this->passwordResetTokenRepository->issue(
            $user->id,
            hash('sha256', $token),
            $now->add(new DateInterval(sprintf('PT%dS', $this->tokenTtlSeconds))),
            $now,
        );

        try {
            $this->passwordResetMailer->sendPasswordReset($user->names, $user->email, $token);
        } catch (EmailDeliveryFailed $exception) {
            $this->passwordResetTokenRepository->markAsUsed($record->id, $now);

            throw $exception;
        }
    }
}
