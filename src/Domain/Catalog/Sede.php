<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

final class Sede
{
    public function __construct(
        public int $id,
        public string $nombre,
        public int $idZona,
        public string $zonaCodigo,
        public string $zonaNombre,
        public string $ubigeoDistrito,
        public string $distritoNombre,
        public string $direccion,
        public float $lat,
        public float $lng,
        public bool $activa,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'id_zona' => $this->idZona,
            'zona' => [
                'id' => $this->idZona,
                'codigo' => $this->zonaCodigo,
                'nombre' => $this->zonaNombre,
            ],
            'ubigeo_distrito' => $this->ubigeoDistrito,
            'distrito' => $this->distritoNombre,
            'direccion' => $this->direccion,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'activa' => $this->activa,
        ];
    }
}