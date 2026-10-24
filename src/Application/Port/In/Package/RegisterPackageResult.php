<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

use App\Application\Port\In\Batch\LimaTime;
use App\Application\Port\Out\Pricing\PackageQuote;
use DateTimeImmutable;
use DateTimeZone;

final readonly class RegisterPackageResult
{
    /** @param array<string, mixed> $batch */
    public function __construct(
        public int $id,
        public string $tracking,
        public string $registeredAt,
        public PackageQuote $quote,
        public array $batch,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tracking_id' => $this->tracking,
            'estado' => 'EN_ALMACEN',
            'fecha_ingreso' => LimaTime::format(new DateTimeImmutable($this->registeredAt, new DateTimeZone('UTC'))),
            'cotizacion' => ['id_tarifa' => $this->quote->tariffId, 'monto' => (float) $this->quote->amount],
            'lote' => $this->batch,
        ];
    }
}
