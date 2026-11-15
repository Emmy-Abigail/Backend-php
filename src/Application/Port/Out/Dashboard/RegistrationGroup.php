<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Dashboard;

/**
 * Paquetes registrados en el período agrupados por sede, operador que los registró, estado actual
 * y motivo de fallo (solo cuando el estado es FALLIDO).
 */
final readonly class RegistrationGroup
{
    public function __construct(
        public int $sedeId,
        public int $operatorId,
        public string $status,
        public ?string $failureReason,
        public int $count,
    ) {
    }
}
