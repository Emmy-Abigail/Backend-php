<?php

declare(strict_types=1);

namespace App\Application\Port\In\Delivery;

use App\Application\Port\In\Auth\AuthenticatedUser;

final readonly class GetEvidenceQuery
{
    public function __construct(public AuthenticatedUser $viewer, public int $packageId)
    {
    }
}
