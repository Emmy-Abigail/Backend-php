<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

interface ConfirmPasswordResetUseCase
{
    public function execute(ConfirmPasswordResetCommand $command): void;
}
