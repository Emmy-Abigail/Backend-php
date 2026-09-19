<?php

declare(strict_types=1);

namespace App\Application\Port\In\User;

interface ListUsersUseCase
{
    public function execute(): ListUsersResult;
}
