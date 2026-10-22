<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Assignment;

use InvalidArgumentException;

final readonly class BatchAssignment
{
    public function __construct(public string $action, public int $vehicleId, public ?int $batchId)
    {
        if ($vehicleId <= 0 || !in_array($action, ['USAR_LOTE', 'CREAR_LOTE'], true)
            || ($action === 'USAR_LOTE' && ($batchId === null || $batchId <= 0))
            || ($action === 'CREAR_LOTE' && $batchId !== null)) {
            throw new InvalidArgumentException('Respuesta de asignacion invalida');
        }
    }
}
