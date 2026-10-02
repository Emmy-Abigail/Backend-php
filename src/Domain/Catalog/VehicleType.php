<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

final class VehicleType
{
    public function __construct(
        public int $id,
        public string $codigo,
        public string $nombre,
        public int $nivel,
        public float $pesoMaxTotalKg,
        public float $ladoMaxCm,
        public int $maxPaquetes,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'nivel' => $this->nivel,
            'peso_max_total_kg' => $this->pesoMaxTotalKg,
            'lado_max_cm' => $this->ladoMaxCm,
            'max_paquetes' => $this->maxPaquetes,
        ];
    }
}