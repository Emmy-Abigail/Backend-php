<?php

declare(strict_types=1);

namespace App\Domain\Package;

final class AddressCorrectionPolicy
{
    public static function requiresForRetry(?string $status, ?string $lastStatus, ?string $reason): bool
    {
        return $status === 'FALLIDO' && $lastStatus === 'FALLIDO' && $reason === 'DIRECCION_ERRONEA';
    }
}
