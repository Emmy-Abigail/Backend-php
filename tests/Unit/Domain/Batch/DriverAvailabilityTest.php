<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Batch;

use App\Domain\Batch\BatchStatus;
use App\Domain\Batch\DriverAvailability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DriverAvailabilityTest extends TestCase
{
    /**
     * @return array<string, array{int, bool}>
     */
    public static function pendingBatches(): array
    {
        return [
            'sin lotes' => [0, true],
            'un lote' => [1, true],
            'dos lotes' => [2, true],
            'tres lotes: llegó al límite' => [3, false],
            'más del límite' => [4, false],
        ];
    }

    #[Test]
    #[DataProvider('pendingBatches')]
    public function puede_recibir_otro_lote_solo_con_menos_de_tres_pendientes(int $pending, bool $expected): void
    {
        self::assertSame($expected, DriverAvailability::canTakeAnotherBatch($pending));
    }

    #[Test]
    public function el_limite_es_tres(): void
    {
        self::assertSame(3, DriverAvailability::MAX_PENDING_BATCHES);
    }

    #[Test]
    public function los_estados_del_lote_coinciden_con_la_base_de_datos(): void
    {
        self::assertSame(
            ['EN_PREPARACION', 'ASIGNADO', 'EN_RUTA', 'CERRADO'],
            array_map(static fn (BatchStatus $status): string => $status->value, BatchStatus::cases()),
        );
    }

    #[Test]
    public function solo_acepta_estados_conocidos(): void
    {
        self::assertSame(BatchStatus::EnRuta, BatchStatus::tryFrom('EN_RUTA'));
        self::assertNull(BatchStatus::tryFrom('en_ruta'));
        self::assertNull(BatchStatus::tryFrom('CANCELADO'));
    }
}
