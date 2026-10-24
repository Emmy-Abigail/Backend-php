<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Delivery;

use App\Application\Port\In\Delivery\EvidencePhoto;

interface EvidenceStorage
{
    /** Clave opaca generada por el servidor, nunca una URL publica. */
    public function store(EvidencePhoto $photo): string;

    public function delete(string $key): void;

    /** Lee un archivo privado sin convertir su clave en una URL publica. */
    public function read(string $key): StoredEvidence;
}
