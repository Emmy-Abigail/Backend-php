<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Persistence;

/** Paquete del que se quiere crear un reintento, con sus datos en el formato del registro. */
final readonly class PackageRetrySource
{
    /**
     * @param array<string, mixed> $registrationInput Remitente, destinatario, dirección y medidas, como los recibe PackageDetails::fromArray.
     */
    public function __construct(
        public int $sedeId,
        public string $status,
        public bool $alreadyRetried,
        /** Reintentos anteriores en la cadena de este envío: 0 si es el paquete original, 1 si es el primer reintento, etc. */
        public int $previousRetries,
        public array $registrationInput,
    ) {
    }
}
