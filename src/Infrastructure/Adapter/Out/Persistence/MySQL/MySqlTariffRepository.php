<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\Out\Pricing\TariffRepository;
use App\Domain\Pricing\Tariff;
use Illuminate\Database\Capsule\Manager as Capsule;

final class MySqlTariffRepository implements TariffRepository
{
    public function findCurrent(): ?Tariff
    {
        $record = Capsule::table('tarifas')
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->first();

        if ($record === null) {
            return null;
        }

        return Tariff::fromDecimalString((int) $record->id, (string) $record->tarifa_base_kg);
    }
}
