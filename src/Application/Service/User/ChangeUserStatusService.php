<?php

declare(strict_types=1);

namespace App\Application\Service\User;

use App\Application\Exception\UserNotFound;
use App\Application\Port\In\User\ChangeUserStatusCommand;
use App\Application\Port\In\User\ChangeUserStatusUseCase;
use App\Application\Port\Out\Persistence\UserRepository;

final readonly class ChangeUserStatusService implements ChangeUserStatusUseCase
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    public function execute(ChangeUserStatusCommand $command): void
    {
        $user = $this->userRepository->findById($command->userId);

        if ($user === null) {
            throw new UserNotFound();
        }

        $this->userRepository->updateStatus($command->userId, $command->active);
    }
}