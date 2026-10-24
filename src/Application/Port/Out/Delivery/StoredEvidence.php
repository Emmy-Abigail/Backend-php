<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Delivery;

/** Contenido privado; no incluye rutas, nombres del cliente ni la clave almacenada. */
final readonly class StoredEvidence
{
    public function __construct(public string $bytes, public string $mediaType)
    {
    }
}
