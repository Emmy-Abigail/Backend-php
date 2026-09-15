<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Persistence;

use App\Domain\User\User;

interface UserRepository
{
    public function findByEmail(string $email): ?User;
}
