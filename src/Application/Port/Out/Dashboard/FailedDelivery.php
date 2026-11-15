<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Dashboard;

use DateTimeImmutable;

/** Un paquete marcado como FALLIDO por el conductor. */
final readonly class FailedDelivery
{
    public function __construct(
        public int $packageId,
        public string $trackingId,
        public string $district,
        public string $sedeName,
        public ?string $reasonCode,
        public ?string $reasonName,
        public DateTimeImmutable $failedAt,
    ) {
    }
}
