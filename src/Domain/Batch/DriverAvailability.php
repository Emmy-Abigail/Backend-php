<?php

declare(strict_types=1);

namespace App\Domain\Batch;

/**
 * Un conductor puede recibir lotes mientras tenga menos de 3 pendientes
 * (lotes Asignados o En Ruta).
 */
final class DriverAvailability
{
    public const MAX_PENDING_BATCHES = 3;

    public static function canTakeAnotherBatch(int $pendingBatches): bool
    {
        return $pendingBatches < self::MAX_PENDING_BATCHES;
    }
}
