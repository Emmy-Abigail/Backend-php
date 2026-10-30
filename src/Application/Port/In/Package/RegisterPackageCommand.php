<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Domain\Package\PackageDetails;

final readonly class RegisterPackageCommand
{
    /** @param int|null $originPackageId Paquete FALLIDO del que este registro es el reintento; null en un registro normal. */
    public function __construct(
        public AuthenticatedUser $operator,
        public PackageDetails $details,
        public ?int $originPackageId = null,
    ) {
    }
}
