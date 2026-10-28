<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Geocoding;

/** Lugar tal como lo devuelve el proveedor de mapas, antes de traducirlo a nuestro catálogo. */
final readonly class GeocodedPlace
{
    /** @param list<string> $districtNames nombres posibles del distrito, del más específico al más general */
    public function __construct(
        public ?string $street,
        public ?string $houseNumber,
        public ?string $placeName,
        public array $districtNames,
        public float $lat,
        public float $lng,
    ) {
    }
}
