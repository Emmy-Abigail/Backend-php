<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

use DateTimeImmutable;

final readonly class BatchSummary
{
    /**
     * @param array{id: int, nombre: string} $sede
     * @param array{id: int, codigo: string, nombre: string} $zone
     * @param array{id: int, codigo: string, nombre: string} $vehicle
     * @param array{id: int, nombres: string, placa: ?string}|null $driver
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $status,
        public int $version,
        public array $sede,
        public array $zone,
        public array $vehicle,
        public int $packageCount,
        public string $totalWeightKg,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $dispatchedAt,
        public ?array $driver,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->code,
            'estado' => $this->status,
            'version' => $this->version,
            'sede' => $this->sede,
            'zona_destino' => $this->zone,
            'vehiculo' => $this->vehicle,
            'cantidad_paquetes' => $this->packageCount,
            'peso_total_kg' => (float) $this->totalWeightKg,
            'conductor' => $this->driver,
            'created_at' => LimaTime::format($this->createdAt),
            'despachado_at' => $this->dispatchedAt === null ? null : LimaTime::format($this->dispatchedAt),
        ];
    }
}
