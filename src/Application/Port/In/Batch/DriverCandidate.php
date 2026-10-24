<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

final readonly class DriverCandidate
{
    public function __construct(
        public int $id,
        public string $names,
        public ?string $placa,
        public int $pendingBatches,
        public bool $onRoute,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nombres' => $this->names,
            'placa' => $this->placa,
            'lotes_pendientes' => $this->pendingBatches,
            'en_ruta' => $this->onRoute,
        ];
    }
}
