<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Dashboard;

use DateTimeImmutable;

/**
 * Un paquete que terminó su recorrido (ENTREGADO o FALLIDO) dentro del período, según el día en que
 * el conductor lo marcó. El conductor y el tipo de vehículo son los del lote en que viajó.
 */
final readonly class TerminalEvent
{
    public function __construct(
        public int $packageId,
        public int $sedeId,
        public string $status,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $registeredAt,
        public ?string $failureReason = null,
        public ?int $driverId = null,
        public ?string $driverName = null,
        public ?string $driverPlate = null,
        public ?string $driverVehicleType = null,
        public ?string $batchVehicleType = null,
        public bool $retried = false,
        public ?string $retryStatus = null,
    ) {
    }

    public function isDelivered(): bool
    {
        return $this->status === 'ENTREGADO';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FALLIDO';
    }
}
