<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Package;

use App\Domain\Package\DeliveryEstimate;
use App\Domain\Package\RetryPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    #[DataProvider('casos')]
    public function test_reintentos_restantes(?string $lastStatus, ?string $reason, int $previous, bool $retried, int $expected): void
    {
        self::assertSame($expected, RetryPolicy::remaining($lastStatus, $reason, $previous, $retried));
    }

    public static function casos(): array
    {
        return [
            'paquete original fallido' => ['FALLIDO', 'CLIENTE_AUSENTE', 0, false, 3],
            'segundo reintento fallido' => ['FALLIDO', 'OTRO', 2, false, 1],
            'tercer reintento ya usado' => ['FALLIDO', 'CLIENTE_AUSENTE', 3, false, 0],
            'rechazado aunque queden intentos' => ['FALLIDO', 'RECHAZADO', 0, false, 0],
            'el paquete ya tiene su reintento' => ['FALLIDO', 'CLIENTE_AUSENTE', 0, true, 0],
            'todavia en almacen' => ['EN_ALMACEN', null, 1, false, 2],
        ];
    }

    public function test_rechazado_solo_cuenta_si_el_ultimo_estado_es_fallido(): void
    {
        self::assertTrue(RetryPolicy::isRejected('FALLIDO', 'RECHAZADO'));
        self::assertFalse(RetryPolicy::isRejected('FALLIDO', 'DIRECCION_ERRONEA'));
        self::assertFalse(RetryPolicy::isRejected('EN_RUTA', 'RECHAZADO'));
    }

    public function test_la_fecha_estimada_es_el_registro_mas_dos_dias(): void
    {
        $registered = new DateTimeImmutable('2026-10-24 15:30:00', new DateTimeZone('UTC'));

        self::assertSame('2026-10-26 15:30:00', DeliveryEstimate::from($registered)->format('Y-m-d H:i:s'));
    }
}
