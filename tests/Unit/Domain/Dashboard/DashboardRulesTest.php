<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Dashboard;

use App\Domain\Dashboard\DashboardMath;
use App\Domain\Dashboard\LowestSedeRule;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DashboardRulesTest extends TestCase
{
    public function test_los_porcentajes_tienen_un_decimal_y_sin_datos_son_null(): void
    {
        self::assertSame(92.5, DashboardMath::percent(1012, 1094));
        self::assertSame(97.5, DashboardMath::percent(154, 158));
        self::assertNull(DashboardMath::percent(0, 0));
        self::assertSame(0.0, DashboardMath::share(0, 0));
    }

    public function test_el_promedio_de_horas_tiene_un_decimal(): void
    {
        $start = new DateTimeImmutable('2026-10-01 10:00:00');

        self::assertSame(1.5, DashboardMath::hoursBetween($start, $start->modify('+90 minutes')));
        self::assertSame(0.0, DashboardMath::hoursBetween($start, $start->modify('-1 hour')));
        self::assertSame(2.3, DashboardMath::averageHours([1.0, 2.0, 4.0]));
        self::assertNull(DashboardMath::averageHours([]));
    }

    public function test_la_sede_mas_baja_es_la_de_menor_porcentaje_con_al_menos_30_terminados(): void
    {
        $lowest = LowestSedeRule::lowest([
            ['id' => 1, 'finished' => 400, 'delivered' => 380, 'failed' => 20],
            ['id' => 4, 'finished' => 214, 'delivered' => 194, 'failed' => 20],
            // Peor porcentaje, pero con pocos datos: no compite.
            ['id' => 5, 'finished' => 29, 'delivered' => 10, 'failed' => 19],
        ]);

        self::assertSame(4, $lowest);
        self::assertFalse(LowestSedeRule::hasEnoughData(29));
        self::assertTrue(LowestSedeRule::hasEnoughData(30));
    }

    public function test_en_empate_queda_mas_baja_la_que_tiene_mas_fallidos(): void
    {
        $lowest = LowestSedeRule::lowest([
            ['id' => 1, 'finished' => 40, 'delivered' => 36, 'failed' => 4],
            ['id' => 2, 'finished' => 80, 'delivered' => 72, 'failed' => 8],
        ]);

        self::assertSame(2, $lowest);
    }

    public function test_sin_sedes_con_datos_suficientes_no_hay_sede_mas_baja(): void
    {
        self::assertNull(LowestSedeRule::lowest([['id' => 1, 'finished' => 5, 'delivered' => 1, 'failed' => 4]]));
        self::assertNull(LowestSedeRule::lowest([]));
    }
}
