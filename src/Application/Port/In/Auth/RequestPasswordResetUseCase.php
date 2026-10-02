<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

interface RequestPasswordResetUseCase
{
    public function execute(RequestPasswordResetCommand $command): void;
}
