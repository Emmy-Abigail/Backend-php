<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

interface ChangePasswordUseCase
{
    public function execute(ChangePasswordCommand $command): void;
}
