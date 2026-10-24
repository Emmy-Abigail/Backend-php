<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Port\In\Package;

use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\Out\Pricing\PackageQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegisterPackageResultTest extends TestCase
{
    public static function registrationDates(): array
    {
        return [
            'UTC con offset' => ['2026-10-24T15:00:00+00:00', '2026-10-24T10:00:00-05:00'],
            'UTC con Z y cambio de dia' => ['2026-10-24T02:00:00Z', '2026-10-23T21:00:00-05:00'],
            'fecha ya en Lima' => ['2026-10-24T10:00:00-05:00', '2026-10-24T10:00:00-05:00'],
            'UTC sin offset explicito' => ['2026-10-24 15:00:00', '2026-10-24T10:00:00-05:00'],
        ];
    }

    #[DataProvider('registrationDates')]
    public function test_la_respuesta_muestra_lima_sin_modificar_la_fecha_original(string $original, string $expected): void
    {
        $result = new RegisterPackageResult(10, 'AB23456789', $original, new PackageQuote(1, '15.50'), ['id' => 20]);
        $serverTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Madrid');
            $data = $result->toArray();
        } finally {
            date_default_timezone_set($serverTimezone);
        }

        self::assertSame($expected, $data['fecha_ingreso']);
        self::assertSame($original, $result->registeredAt);
        self::assertSame('AB23456789', $data['tracking_id']);
        self::assertSame('EN_ALMACEN', $data['estado']);
        self::assertSame(['id_tarifa' => 1, 'monto' => 15.5], $data['cotizacion']);
        self::assertSame(['id' => 20], $data['lote']);
    }
}
