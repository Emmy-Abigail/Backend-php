<?php

declare(strict_types=1);

namespace App\Application\Port\In\Delivery;

use App\Application\Exception\InvalidFinalStatus;
use App\Application\Port\In\Auth\AuthenticatedUser;

final readonly class FinalStatusCommand
{
    public ?string $observation;

    public function __construct(
        public AuthenticatedUser $driver,
        public int $packageId,
        public string $status,
        public ?int $reasonId = null,
        ?string $observation = null,
        public ?EvidencePhoto $photo = null,
    ) {
        $this->observation = $observation === null ? null : trim($observation);
        if ($packageId < 1 || !in_array($status, ['ENTREGADO', 'FALLIDO'], true)) {
            throw new InvalidFinalStatus('El paquete y el estado final deben ser validos');
        }
        // TEXT admite hasta 65535 bytes. Comprobar antes de abrir una transaccion.
        if ($observation !== null && (strlen($observation) > 65535 || preg_match('//u', $observation) !== 1)) {
            throw new InvalidFinalStatus('La observacion debe ser texto UTF-8 de hasta 65535 bytes');
        }
        if ($status === 'ENTREGADO' && ($photo === null || $reasonId !== null)) {
            throw new InvalidFinalStatus('ENTREGADO requiere una foto y no admite motivo de fallo');
        }
        if ($status === 'FALLIDO' && ($photo !== null || $reasonId === null || $reasonId < 1 || $this->observation === null || $this->observation === '')) {
            throw new InvalidFinalStatus('FALLIDO requiere id_motivo y observacion, y no admite foto');
        }
    }
}
