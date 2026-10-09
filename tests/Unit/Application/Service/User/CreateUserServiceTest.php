<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\User;

use App\Application\Exception\DniAlreadyExists;
use App\Application\Exception\EmailAlreadyExists;
use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\PlacaAlreadyExists;
use App\Application\Port\In\User\CreateUserCommand;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Notification\UserCredentialsMailer;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Service\User\CreateUserService;
use App\Domain\Catalog\VehicleType;
use App\Domain\User\PasswordPolicy;
use App\Domain\User\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateUserServiceTest extends TestCase
{
    private function catalogWithVehicleType(int $id = 1): CatalogRepository
    {
        $catalog = $this->createStub(CatalogRepository::class);
        $catalog->method('getVehicleTypes')->willReturn([
            new VehicleType($id, 'MOTORIZADO', 'Motorizado', 1, 20.0, 40.0, 20),
        ]);
        $catalog->method('getSedes')->willReturn([]);

        return $catalog;
    }

    private function conductorCommand(string $placa = 'ABC123'): CreateUserCommand
    {
        return new CreateUserCommand('Conductor Uno', '12345678', 'conductor@email.com', '999888777', 'CONDUCTOR', null, 1, $placa);
    }

    #[Test]
    public function crea_un_conductor_con_placa_y_contrasena_temporal_fuerte(): void
    {
        $generatedHash = null;
        $temporaryPasswordSent = null;

        $repository = $this->createMock(UserRepository::class);
        $repository->method('existsByEmail')->willReturn(false);
        $repository->method('existsByDni')->willReturn(false);
        $repository->expects($this->once())->method('existsByPlaca')->with('ABC123')->willReturn(false);
        $repository->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (
                string $names,
                string $dni,
                string $email,
                ?string $phone,
                string $passwordHash,
                string $role,
                ?int $idSede,
                ?int $idTipoVehiculo,
                ?string $placa = null,
            ) use (&$generatedHash): User {
                $generatedHash = $passwordHash;
                self::assertSame('ABC123', $placa);

                return new User(2, $names, $email, $passwordHash, $role, true, $phone, true, $dni, $idSede, $idTipoVehiculo, null, $placa);
            });

        $mailer = $this->createMock(UserCredentialsMailer::class);
        $mailer->expects($this->once())
            ->method('sendTemporaryPassword')
            ->willReturnCallback(function (string $name, string $email, string $password) use (&$temporaryPasswordSent): void {
                self::assertSame('Conductor Uno', $name);
                self::assertSame('conductor@email.com', $email);
                $temporaryPasswordSent = $password;
            });

        $result = (new CreateUserService($repository, $this->catalogWithVehicleType(), $mailer))->execute($this->conductorCommand());

        self::assertIsString($temporaryPasswordSent);
        self::assertTrue(PasswordPolicy::isValid($temporaryPasswordSent));
        self::assertIsString($generatedHash);
        self::assertTrue(password_verify($temporaryPasswordSent, $generatedHash));
        self::assertSame(2, $result->id);
        self::assertSame('CONDUCTOR', $result->role);
        self::assertSame('ABC123', $result->placa);
        self::assertTrue($result->emailSent);
    }

    #[Test]
    public function rechaza_una_placa_existente_sin_intentar_crear_el_usuario(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('existsByEmail')->willReturn(false);
        $repository->method('existsByDni')->willReturn(false);
        $repository->method('existsByPlaca')->willReturn(true);
        $repository->expects($this->never())->method('create');

        $mailer = $this->createMock(UserCredentialsMailer::class);
        $mailer->expects($this->never())->method('sendTemporaryPassword');

        $service = new CreateUserService($repository, $this->catalogWithVehicleType(), $mailer);

        $this->expectException(PlacaAlreadyExists::class);
        $service->execute($this->conductorCommand());
    }

    #[Test]
    public function rechaza_un_correo_existente_sin_intentar_crear_el_usuario(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('existsByEmail')->willReturn(true);
        $repository->expects($this->never())->method('create');

        $service = new CreateUserService($repository, $this->catalogWithVehicleType(), $this->createStub(UserCredentialsMailer::class));

        $this->expectException(EmailAlreadyExists::class);
        $service->execute($this->conductorCommand());
    }

    #[Test]
    public function rechaza_un_dni_existente_sin_intentar_crear_el_usuario(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('existsByEmail')->willReturn(false);
        $repository->method('existsByDni')->willReturn(true);
        $repository->expects($this->never())->method('create');

        $service = new CreateUserService($repository, $this->catalogWithVehicleType(), $this->createStub(UserCredentialsMailer::class));

        $this->expectException(DniAlreadyExists::class);
        $service->execute($this->conductorCommand());
    }

    #[Test]
    public function crea_el_usuario_aunque_falle_el_envio_del_correo(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->method('existsByEmail')->willReturn(false);
        $repository->method('existsByDni')->willReturn(false);
        $repository->method('existsByPlaca')->willReturn(false);
        $repository->expects($this->once())
            ->method('create')
            ->willReturn(new User(2, 'Conductor Uno', 'conductor@email.com', 'hash', 'CONDUCTOR', true, null, true, '12345678', null, 1, null, 'ABC123'));
        $repository->expects($this->never())->method('deleteById');

        $mailer = $this->createMock(UserCredentialsMailer::class);
        $mailer->method('sendTemporaryPassword')->willThrowException(new \RuntimeException('SMTP caído'));

        $result = (new CreateUserService($repository, $this->catalogWithVehicleType(), $mailer))->execute($this->conductorCommand());

        self::assertFalse($result->emailSent);
        self::assertSame('ABC123', $result->placa);
    }
}