<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Pricing;

use App\Domain\Pricing\Tariff;

interface TariffRepository
{
    /**
     * Tarifa vigente más reciente (sin incluir fechas futuras), o null si no hay ninguna.
     */
    public function findCurrent(): ?Tariff;
}
