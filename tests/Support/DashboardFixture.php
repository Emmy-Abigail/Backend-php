<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\Out\Dashboard\FailedDelivery;
use App\Application\Port\Out\Dashboard\TerminalEvent;
use App\Domain\Catalog\FailureReason;
use App\Domain\Catalog\Sede;
use App\Domain\Catalog\VehicleType;
use DateTimeImmutable;
use DateTimeZone;

final class DashboardFixture
{
    public static function sede(int $id, string $name, bool $active = true): Sede
    {
        return new Sede($id, $name, $id, 'Z' . $id, 'Zona ' . $id, '150101', 'Lima', 'Av. Prueba', -12.0, -77.0, $active);
    }

    /** @return list<FailureReason> */
    public static function failureReasons(): array
    {
        return [
            new FailureReason(1, 'CLIENTE_AUSENTE', 'Cliente ausente'),
            new FailureReason(2, 'DIRECCION_ERRONEA', 'Dirección errónea'),
            new FailureReason(3, 'RECHAZADO', 'Rechazado por destinatario'),
            new FailureReason(4, 'OTRO', 'Otros'),
        ];
    }

    /** @return list<VehicleType> */
    public static function vehicleTypes(): array
    {
        return [
            new VehicleType(1, 'MOTORIZADO', 'Motorizado', 1, 20, 40, 20),
            new VehicleType(2, 'AUTO', 'Auto', 2, 200, 125, 20),
            new VehicleType(3, 'CAMION', 'Camión', 3, 1000, 150, 20),
        ];
    }

    public static function utc(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    public static function delivered(
        int $packageId,
        int $sedeId,
        int $hours,
        int $driverId = 11,
        string $driverName = 'Luis Ramos',
        string $vehicle = 'MOTORIZADO',
    ): TerminalEvent {
        $registered = self::utc('2026-10-10 12:00:00');

        return new TerminalEvent(
            packageId: $packageId,
            sedeId: $sedeId,
            status: 'ENTREGADO',
            occurredAt: $registered->modify('+' . $hours . ' hours'),
            registeredAt: $registered,
            driverId: $driverId,
            driverName: $driverName,
            driverPlate: 'ABC-' . $driverId,
            driverVehicleType: $vehicle,
            batchVehicleType: $vehicle,
        );
    }

    public static function failed(
        int $packageId,
        int $sedeId,
        string $reason,
        bool $retried = false,
        ?string $retryStatus = null,
    ): TerminalEvent {
        $registered = self::utc('2026-10-10 12:00:00');

        return new TerminalEvent(
            packageId: $packageId,
            sedeId: $sedeId,
            status: 'FALLIDO',
            occurredAt: $registered->modify('+5 hours'),
            registeredAt: $registered,
            failureReason: $reason,
            driverId: 11,
            driverName: 'Luis Ramos',
            driverPlate: 'ABC-11',
            driverVehicleType: 'MOTORIZADO',
            batchVehicleType: 'MOTORIZADO',
            retried: $retried,
            retryStatus: $retryStatus,
        );
    }

    public static function failedDelivery(int $packageId): FailedDelivery
    {
        return new FailedDelivery(
            $packageId,
            'TRK' . $packageId,
            'Barranco',
            'Sede Sur',
            'CLIENTE_AUSENTE',
            'Cliente ausente',
            self::utc('2026-10-20 19:30:00'),
        );
    }
}
