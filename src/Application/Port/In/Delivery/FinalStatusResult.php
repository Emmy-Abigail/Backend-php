<?php

declare(strict_types=1);

namespace App\Application\Port\In\Delivery;

use App\Application\Port\In\Batch\LimaTime;
use DateTimeImmutable;

final readonly class FinalStatusResult
{
    public function __construct(
        public int $packageId,
        public string $trackingId,
        public string $status,
        public DateTimeImmutable $occurredAt,
        public bool $hasEvidence,
        public int $batchId,
        public bool $batchClosed,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->packageId,
            'tracking_id' => $this->trackingId,
            'estado' => $this->status,
            'fecha' => LimaTime::format($this->occurredAt),
            'tiene_evidencia' => $this->hasEvidence,
            'lote' => [
                'id' => $this->batchId,
                'estado' => $this->batchClosed ? 'CERRADO' : 'EN_RUTA',
                'closed_at' => $this->batchClosed ? LimaTime::format($this->occurredAt) : null,
            ],
        ];
    }
}
