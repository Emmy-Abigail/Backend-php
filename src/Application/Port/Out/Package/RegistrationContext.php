<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Package;

final readonly class RegistrationContext
{
    public function __construct(
        public int $sedeId,
        public int $originZoneId,
        public string $originZoneCode,
        public string $destinationUbigeo,
        public int $destinationZoneId,
        public string $destinationZoneCode,
    ) {
    }
}
