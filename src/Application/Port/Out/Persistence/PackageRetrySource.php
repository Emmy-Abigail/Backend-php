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
        public array $registrationInput,
    ) {
    }
}
