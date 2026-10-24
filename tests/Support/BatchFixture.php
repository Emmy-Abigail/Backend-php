<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\BatchPackage;
use App\Application\Port\In\Batch\BatchSummary;
use App\Application\Port\In\Batch\DispatchBatchResult;
use App\Application\Port\In\Batch\DriverCandidate;
use DateTimeImmutable;
use DateTimeZone;

final class BatchFixture
{
    public static function operator(int $sedeId = 1, bool $mustChangePassword = false): AuthenticatedUser
    {
        return new AuthenticatedUser(5, 'Ana Torres', 'ana@email.com', 'OPERADOR', $mustChangePassword, $sedeId, 'Sede Norte');
    }

    public static function operatorWithoutSede(): AuthenticatedUser
    {
        return new AuthenticatedUser(5, 'Ana Torres', 'ana@email.com', 'OPERADOR', false, null, null);
    }

    public static function driver(int $id = 31): AuthenticatedUser
    {
        return new AuthenticatedUser($id, 'Juan Perez', 'juan@email.com', 'CONDUCTOR', false, null, null, 1, 'Motorizado', 20.0, 40.0, 'AB5555');
    }

    public static function admin(): AuthenticatedUser
    {
        return new AuthenticatedUser(1, 'Administrador', 'admin@email.com', 'ADMINISTRADOR', false);
    }

    /** @param array{id: int, nombres: string, placa: ?string}|null $driver */
    public static function summary(string $status = 'EN_PREPARACION', int $sedeId = 1, ?array $driver = null, int $id = 210): BatchSummary
    {
        $utc = new DateTimeZone('UTC');

        return new BatchSummary(
            $id,
            'L-ABC123',
            $status,
            0,
            ['id' => $sedeId, 'nombre' => 'Sede Norte'],
            ['id' => 3, 'codigo' => 'SUR', 'nombre' => 'Sur'],
            ['id' => 1, 'codigo' => 'MOTORIZADO', 'nombre' => 'Motorizado'],
            2,
            '8.50',
            new DateTimeImmutable('2026-10-21 13:00:00', $utc),
            $driver === null ? null : new DateTimeImmutable('2026-10-21 15:00:00', $utc),
            $driver,
        );
    }

    /** @return array{id: int, nombres: string, placa: ?string} */
    public static function driverData(int $id = 31): array
    {
        return ['id' => $id, 'nombres' => 'Juan Perez', 'placa' => 'AB5555'];
    }

    public static function package(int $id, ?int $order = null, string $status = 'EN_ALMACEN'): BatchPackage
    {
        return new BatchPackage(
            $id,
            'TRK' . $id,
            $status,
            $order,
            '150122',
            'Miraflores',
            '2.00',
            '30.0',
            new DateTimeImmutable('2026-10-21 13:05:00', new DateTimeZone('UTC')),
            ['nombres' => 'Rosa Diaz', 'dni' => '41234567', 'celular' => '912345678'],
            ['tipo_via' => 'Av.', 'nombre_via' => 'Los Robles', 'numero' => '123', 'manzana' => null, 'lote' => null, 'referencia' => 'Frente al parque'],
        );
    }

    public static function candidate(int $id = 31, int $pending = 0, bool $onRoute = false): DriverCandidate
    {
        return new DriverCandidate($id, 'Juan Perez', 'AB5555', $pending, $onRoute);
    }

    public static function dispatchResult(): DispatchBatchResult
    {
        return new DispatchBatchResult(
            210,
            'L-ABC123',
            1,
            self::driverData(),
            ['id' => 5, 'nombres' => 'Ana Torres'],
            new DateTimeImmutable('2026-10-21 15:00:00', new DateTimeZone('UTC')),
            [
                ['orden' => 1, 'id_paquete' => 5003, 'tracking_id' => 'TRK5003', 'distrito' => ['ubigeo' => '150122', 'nombre' => 'Miraflores']],
                ['orden' => 2, 'id_paquete' => 4991, 'tracking_id' => 'TRK4991', 'distrito' => ['ubigeo' => '150104', 'nombre' => 'Barranco']],
            ],
        );
    }
}
