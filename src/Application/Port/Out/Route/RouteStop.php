<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Route;

final readonly class RouteStop
{
    public function __construct(
        public int $packageId,
        public string $ubigeo,
    ) {
    }
}
