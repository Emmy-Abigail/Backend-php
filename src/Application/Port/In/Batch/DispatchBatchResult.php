<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

use DateTimeImmutable;

final readonly class DispatchBatchResult
{
    /**
     * @param array{id: int, nombres: string, placa: ?string} $driver
     * @param array{id: int, nombres: string} $dispatchedBy
     * @param list<array{orden: int, id_paquete: int, tracking_id: string, distrito: array{ubigeo: string, nombre: string}}> $stops
     */
    public function __construct(
        public int $batchId,
        public string $code,
        public int $version,
        public array $driver,
        public array $dispatchedBy,
        public DateTimeImmutable $dispatchedAt,
        public array $stops,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->batchId,
            'codigo' => $this->code,
            'estado' => 'ASIGNADO',
            'version' => $this->version,
            'conductor' => $this->driver,
            'despachado_por' => $this->dispatchedBy,
            'despachado_at' => LimaTime::format($this->dispatchedAt),
            'paradas' => $this->stops,
        ];
    }
}
