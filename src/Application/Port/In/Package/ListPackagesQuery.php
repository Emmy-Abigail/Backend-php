<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

use App\Application\Port\In\Auth\AuthenticatedUser;

final readonly class ListPackagesQuery
{
    public function __construct(
        public AuthenticatedUser $operator,
        public ?string $search = null,
        public ?string $status = null,
        public ?string $from = null,
        public ?string $to = null,
        public int $page = 1,
        public int $perPage = 20,
    ) {
    }
}
