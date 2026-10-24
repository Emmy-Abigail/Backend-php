<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Persistence;

/**
 * Detalle completo de un paquete tal como está en la base de datos.
 * Los campos de control (sede, conductor y estado del lote) sirven para decidir quién puede verlo.
 */
final readonly class PackageDetail
{
    /**
     * @param array<string, mixed> $data    Detalle listo para responder al operador.
     * @param list<array<string, mixed>> $history Cambios de estado, del más antiguo al más reciente.
     */
    public function __construct(
        public int $sedeId,
        public ?int $driverId,
        public ?string $batchStatus,
        public array $data,
        public array $history,
    ) {
    }
}
