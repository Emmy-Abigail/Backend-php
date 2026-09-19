<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\User;

use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Service\User\ListUsersService;
use App\Domain\User\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListUsersServiceTest extends TestCase
{
    #[Test]
    public function lista_los_usuarios_sin_exponer_su_hash_de_contrasena(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('findAll')
            ->willReturn([
                new User(1, 'Administrador', 'admin@email.com', 'hash-secreto', 'Admin', true, null),
                new User(2, 'Conductor', 'conductor@email.com', 'otro-hash', 'Conductor', false, '999888777'),
            ]);

        $result = (new ListUsersService($repository))->execute();

        self::assertCount(2, $result->users);
        self::assertSame('Administrador', $result->users[0]->names);
        self::assertNull($result->users[0]->phone);
        self::assertSame('999888777', $result->users[1]->phone);
        self::assertFalse($result->users[1]->active);
        self::assertFalse(property_exists($result->users[0], 'passwordHash'));
    }

    #[Test]
    public function devuelve_una_lista_vacia_cuando_no_hay_personal_registrado(): void
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findAll')->willReturn([]);

        $result = (new ListUsersService($repository))->execute();

        self::assertSame([], $result->users);
    }
}
