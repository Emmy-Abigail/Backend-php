<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

use App\Application\Port\In\Auth\AuthenticatedUser;

final readonly class GetPackageQuery
{
    public function __construct(
        public AuthenticatedUser $viewer,
        public int $packageId,
    ) {
    }
}
