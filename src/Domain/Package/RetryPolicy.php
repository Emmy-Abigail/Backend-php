<?php

declare(strict_types=1);

namespace App\Domain\Package;

/**
 * Reglas de reasignación de un paquete FALLIDO (DEMO 4, "Reasignación de paquetes fallidos"):
 * como máximo MAX_RETRIES reintentos por envío, uno por paquete, y nunca si el destinatario lo rechazó.
 */
final class RetryPolicy
{
    /** El original más 3 reintentos = 4 intentos de entrega por envío. */
    public const MAX_RETRIES = 3;

    /** RECHAZADO cierra el flujo: no se permite ningún reintento, nunca. */
    public static function isRejected(?string $lastStatus, ?string $reason): bool
    {
        return $lastStatus === 'FALLIDO' && $reason === 'RECHAZADO';
    }

    /** Reintentos que todavía se pueden crear desde este paquete; 0 cuando el botón "Reasignar" no debe ofrecerse. */
    public static function remaining(?string $lastStatus, ?string $reason, int $previousRetries, bool $alreadyRetried): int
    {
        if ($alreadyRetried || self::isRejected($lastStatus, $reason)) {
            return 0;
        }

        return max(0, self::MAX_RETRIES - $previousRetries);
    }
}
