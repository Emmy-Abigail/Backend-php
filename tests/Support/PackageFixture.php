<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\RegisterPackageCommand;
use App\Application\Port\Out\Assignment\AssignmentSnapshot;
use App\Application\Port\Out\Package\RegistrationContext;
use App\Domain\Package\PackageDetails;

final class PackageFixture
{
    public static function body(): array
    {
        return [
            'remitente' => ['tipo_documento' => 'DNI', 'numero_documento' => '45678912', 'nombres' => 'Remitente de prueba', 'celular' => '+51 987654321'],
            'destinatario' => ['nombres' => 'Destinatario de prueba', 'dni' => '41234567', 'celular' => '912345678'],
            'direccion' => ['ubigeo_distrito' => '150122', 'tipo_via' => 'Av.', 'nombre_via' => 'Via de prueba', 'numero' => '123', 'referencia' => 'Referencia de prueba'],
            'peso_kg' => 3,
            'largo_cm' => 30,
            'ancho_cm' => 22,
            'alto_cm' => 10,
        ];
    }

    public static function command(): RegisterPackageCommand
    {
        return new RegisterPackageCommand(
            new AuthenticatedUser(2, 'Operador de prueba', 'operator@example.invalid', 'OPERADOR', false, 1),
            PackageDetails::fromArray(self::body()),
        );
    }

    public static function context(): RegistrationContext
    {
        return new RegistrationContext(1, 1, 'NORTE', '150122', 3, 'SUR');
    }

    public static function snapshot(array $batches = []): AssignmentSnapshot
    {
        return new AssignmentSnapshot(self::context(), self::command()->details, [
            ['id' => 1, 'nivel' => 1, 'peso_max_total_kg' => '20.00', 'lado_max_cm' => '40.0', 'max_paquetes' => 20],
            ['id' => 2, 'nivel' => 2, 'peso_max_total_kg' => '200.00', 'lado_max_cm' => '125.0', 'max_paquetes' => 20],
            ['id' => 3, 'nivel' => 3, 'peso_max_total_kg' => '1000.00', 'lado_max_cm' => '150.0', 'max_paquetes' => 20],
        ], $batches);
    }
}
