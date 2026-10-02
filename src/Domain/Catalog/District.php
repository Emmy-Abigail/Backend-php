<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

final class District
{
    public function __construct(
        public string $ubigeo,
        public string $provinceUbigeo,
        public int $zoneId,
        public string $officialName,
        public string $displayName,
        public float $lat,
        public float $lng,
        public bool $active,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ubigeo' => $this->ubigeo,
            'ubigeo_provincia' => $this->provinceUbigeo,
            'id_zona' => $this->zoneId,
            'nombre_oficial' => $this->officialName,
            'nombre_mostrado' => $this->displayName,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'activo' => $this->active,
        ];
    }
}