<?php

declare(strict_types=1);

namespace App\Application\Port\In\User;

interface ChangeUserStatusUseCase
{
    public function execute(ChangeUserStatusCommand $command): void;
}