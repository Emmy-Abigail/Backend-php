<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\User;

use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Service\User\ListUsersService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListUsersServiceTest extends TestCase
{
    #[Test]
    public function lista_los_usuarios_con_detalles_sin_exponer_hash_de_contrasena(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('findAllWithDetails')
            ->willReturn([
                [
                    'id' => 1,
                    'nombres' => 'Administrador',
                    'correo' => 'admin@email.com',
                    'dni' => '11111111',
                    'telefono' => null,
                    'rol' => 'ADMINISTRADOR',
                    'activo' => true,
                    'id_sede' => null,
                    'id_tipo_vehiculo' => null,
                    'sede_nombre' => null,
                    'tipo_vehiculo' => null,
                    'placa' => null,
                ],
                [
                    'id' => 2,
                    'nombres' => 'Conductor Prueba',
                    'correo' => 'conductor@email.com',
                    'dni' => '22222222',
                    'telefono' => '999888777',
                    'rol' => 'CONDUCTOR',
                    'activo' => false,
                    'id_sede' => null,
                    'id_tipo_vehiculo' => 1,
                    'sede_nombre' => null,
                    'tipo_vehiculo' => 'Motorizado',
                    'placa' => 'AB5555',
                ],
            ]);

        $result = (new ListUsersService($repository))->execute();

        self::assertCount(2, $result->users);
        self::assertSame('Administrador', $result->users[0]->names);
        self::assertNull($result->users[0]->phone);
        self::assertSame('999888777', $result->users[1]->phone);
        self::assertFalse($result->users[1]->active);
        self::assertSame('Motorizado', $result->users[1]->tipoVehiculo);
        self::assertSame('AB5555', $result->users[1]->placa);
    }

    #[Test]
    public function devuelve_una_lista_vacia_cuando_no_hay_personal_registrado(): void
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findAllWithDetails')->willReturn([]);

        $result = (new ListUsersService($repository))->execute();

        self::assertSame([], $result->users);
    }
}