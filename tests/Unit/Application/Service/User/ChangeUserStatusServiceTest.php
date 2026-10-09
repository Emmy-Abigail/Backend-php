<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\User;

use App\Application\Exception\CannotDeactivateAdministrator;
use App\Application\Exception\CannotDeactivateSelf;
use App\Application\Exception\UserNotFound;
use App\Application\Port\In\User\ChangeUserStatusCommand;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Service\User\ChangeUserStatusService;
use App\Domain\User\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChangeUserStatusServiceTest extends TestCase
{
    #[Test]
    public function no_permite_desactivar_la_propia_cuenta(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(new User(1, 'Admin', 'admin@email.com', 'hash', 'ADMINISTRADOR', true));

        $service = new ChangeUserStatusService($repository);

        $this->expectException(CannotDeactivateSelf::class);

        $service->execute(new ChangeUserStatusCommand(1, false, 1));
    }

    #[Test]
    public function no_permite_desactivar_a_un_administrador(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('findById')
            ->with(2)
            ->willReturn(new User(2, 'Otro Admin', 'otro@admin.com', 'hash', 'ADMINISTRADOR', true));

        $service = new ChangeUserStatusService($repository);

        $this->expectException(CannotDeactivateAdministrator::class);

        $service->execute(new ChangeUserStatusCommand(2, false, 1));
    }

    #[Test]
    public function lanza_excepcion_si_el_usuario_no_existe(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $service = new ChangeUserStatusService($repository);

        $this->expectException(UserNotFound::class);

        $service->execute(new ChangeUserStatusCommand(999, false, 1));
    }

    #[Test]
    public function desactiva_a_un_operador_correctamente(): void
    {
        $operador = new User(5, 'Operador Uno', 'operador@empresa.com', 'hash', 'OPERADOR', true);

        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('findById')
            ->with(5)
            ->willReturn($operador);

        $repository->expects($this->once())
            ->method('updateStatus')
            ->with(5, false);

        $service = new ChangeUserStatusService($repository);
        $service->execute(new ChangeUserStatusCommand(5, false, 1));

        self::assertTrue(true);
    }
}