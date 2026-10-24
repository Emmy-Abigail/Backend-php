<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

use DateTimeImmutable;

final readonly class StartRouteResult
{
    public function __construct(
        public int $batchId,
        public string $code,
        public DateTimeImmutable $startedAt,
        public int $packageCount,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->batchId,
            'codigo' => $this->code,
            'estado' => 'EN_RUTA',
            'started_at' => LimaTime::format($this->startedAt),
            'cantidad_paquetes' => $this->packageCount,
        ];
    }
}
