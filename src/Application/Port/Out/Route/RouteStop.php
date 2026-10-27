<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Route;

final readonly class RouteStop
{
    /** Sin lat/lng, Python ubica la parada en el centroide del distrito. */
    public function __construct(
        public int $packageId,
        public string $ubigeo,
        public ?float $lat = null,
        public ?float $lng = null,
    ) {
    }
}
