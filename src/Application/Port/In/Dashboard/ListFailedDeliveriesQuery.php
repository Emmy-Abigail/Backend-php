<?php

declare(strict_types=1);

namespace App\Application\Port\In\Dashboard;

use App\Application\Port\In\Auth\AuthenticatedUser;

final readonly class ListFailedDeliveriesQuery
{
    public function __construct(
        public AuthenticatedUser $user,
        public string $from,
        public string $to,
        public ?int $sedeId = null,
        public int $page = 1,
        public int $perPage = 8,
    ) {
    }
}
