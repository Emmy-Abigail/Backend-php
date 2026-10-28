<?php

declare(strict_types=1);

namespace App\Application\Port\In\Geocoding;

/** Dirección lista para llenar el formulario de registro. */
final readonly class AddressSuggestion
{
    public function __construct(
        public string $label,
        public ?string $roadType,
        public ?string $roadName,
        public ?string $number,
        public string $ubigeo,
        public string $district,
        public float $lat,
        public float $lng,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'etiqueta' => $this->label,
            'tipo_via' => $this->roadType,
            'nombre_via' => $this->roadName,
            'numero' => $this->number,
            'ubigeo_distrito' => $this->ubigeo,
            'distrito' => $this->district,
            'lat' => round($this->lat, 6),
            'lng' => round($this->lng, 6),
        ];
    }
}
