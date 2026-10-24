<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Batch;

use App\Application\Port\Out\Route\RouteStop;

/** Estado del lote leído sin bloqueos, antes de pedir la ruta a Python. */
final readonly class DispatchSnapshot
{
    /** @param list<RouteStop> $stops paradas en orden de ingreso */
    public function __construct(
        public int $batchId,
        public int $version,
        public int $sedeId,
        public int $vehicleTypeId,
        public array $stops,
    ) {
    }
}
