<?php

declare(strict_types=1);

namespace App\Application\Port\In\User;

final readonly class ListUsersResult
{
    /**
     * @param list<UserListItem> $users
     */
    public function __construct(public array $users)
    {
    }
}
