<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Domain\Package\PackageDetails;

final readonly class RegisterPackageCommand
{
    public function __construct(public AuthenticatedUser $operator, public PackageDetails $details)
    {
    }
}
