<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Security;

interface RateLimiter
{
    /** Registra una consulta para la clave y decide si todavía está dentro del límite. */
    public function hit(string $key): RateLimitDecision;
}
