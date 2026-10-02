<?php

declare(strict_types=1);

namespace App\Application\Port\In\Auth;

final readonly class RequestPasswordResetCommand
{
    public function __construct(public string $email)
    {
    }
}
