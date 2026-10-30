<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

use App\Application\Port\In\Auth\AuthenticatedUser;

final readonly class RetryPackageCommand
{
    /**
     * @param array{direccion?: array<string, mixed>, destinatario?: array{celular?: mixed}} $corrections
     *        Datos corregidos opcionales; lo que no se envía se copia del paquete fallido.
     */
    public function __construct(
        public AuthenticatedUser $operator,
        public int $packageId,
        public array $corrections = [],
    ) {
    }
}
