<?php

declare(strict_types=1);

namespace App\Application\Port\In\Dashboard;

use App\Application\Port\In\Batch\LimaTime;
use App\Application\Port\Out\Dashboard\FailedDelivery;

final readonly class FailedDeliveriesResult
{
    /** @param list<FailedDelivery> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public string $from,
        public string $to,
        public ?string $sedeName,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (FailedDelivery $item): array => [
                'id' => $item->packageId,
                'tracking_id' => $item->trackingId,
                'destino' => $item->district,
                'sede' => $item->sedeName,
                'motivo' => $item->reasonName,
                'motivo_codigo' => $item->reasonCode,
                'fecha_fallo' => LimaTime::format($item->failedAt),
            ], $this->items),
            'meta' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'total_pages' => (int) ceil($this->total / $this->perPage),
                'sede_nombre' => $this->sedeName,
                'desde' => $this->from,
                'hasta' => $this->to,
            ],
        ];
    }
}
