<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\ZoneFactorMatrix;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ZoneFactorMatrixTest extends TestCase
{
    /**
     * Matriz del documento de arquitectura, escrita a mano (filas: origen,
     * columnas: destino) en el orden Norte, Centro, Sur, Este, Oeste.
     */
    private const EXPECTED = [
        1 => [1 => 10, 2 => 12, 3 => 15, 4 => 15, 5 => 15],
        2 => [1 => 12, 2 => 10, 3 => 12, 4 => 12, 5 => 12],
        3 => [1 => 15, 2 => 12, 3 => 10, 4 => 15, 5 => 15],
        4 => [1 => 15, 2 => 12, 3 => 15, 4 => 10, 5 => 15],
        5 => [1 => 15, 2 => 12, 3 => 15, 4 => 15, 5 => 10],
    ];

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function combinations(): array
    {
        $cases = [];

        foreach (self::EXPECTED as $origin => $row) {
            foreach ($row as $destination => $expected) {
                $cases[sprintf('zona %d -> zona %d', $origin, $destination)] = [$origin, $destination, $expected];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('combinations')]
    public function devuelve_el_factor_de_cada_una_de_las_25_combinaciones(int $origin, int $destination, int $expected): void
    {
        self::assertSame($expected, ZoneFactorMatrix::factorTenths($origin, $destination));
    }

    #[Test]
    #[DataProvider('unknownZones')]
    public function rechaza_zonas_desconocidas(int $origin, int $destination): void
    {
        $this->expectException(InvalidArgumentException::class);

        ZoneFactorMatrix::factorTenths($origin, $destination);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function unknownZones(): array
    {
        return [
            'origen 0' => [0, 1],
            'origen 6' => [6, 1],
            'destino 0' => [1, 0],
            'destino 6' => [1, 6],
        ];
    }
}
