<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Dashboard;

use DateTimeImmutable;

/** Un paquete de un lote despachado en el período, con el operador que despachó el lote. */
final readonly class DispatchedPackage
{
    public function __construct(
        public int $operatorId,
        public DateTimeImmutable $registeredAt,
        public DateTimeImmutable $dispatchedAt,
    ) {
    }
}
