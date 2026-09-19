<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\User;

use App\Application\Exception\EmailAlreadyExists;
use App\Application\Port\In\User\CreateUserCommand;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Service\User\CreateUserService;
use App\Domain\User\PasswordPolicy;
use App\Domain\User\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateUserServiceTest extends TestCase
{
    #[Test]
    public function crea_un_usuario_con_contrasena_temporal_fuerte_y_solicita_su_cambio(): void
    {
        $generatedHash = null;
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('existsByEmail')
            ->with('conductor@email.com')
            ->willReturn(false);
        $repository->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (string $names, string $email, ?string $phone, string $passwordHash, string $role) use (&$generatedHash): User {
                $generatedHash = $passwordHash;

                return new User(2, $names, $email, $passwordHash, $role, true);
            });

        $result = (new CreateUserService($repository))->execute(
            new CreateUserCommand('Conductor Uno', 'conductor@email.com', '999888777', 'Conductor'),
        );

        self::assertTrue(PasswordPolicy::isValid($result->temporaryPassword));
        self::assertIsString($generatedHash);
        self::assertTrue(password_verify($result->temporaryPassword, $generatedHash));
        self::assertSame(2, $result->id);
        self::assertSame('Conductor', $result->role);
    }

    #[Test]
    public function rechaza_un_correo_existente_sin_intentar_crear_el_usuario(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('existsByEmail')
            ->willReturn(true);
        $repository->expects($this->never())->method('create');

        $service = new CreateUserService($repository);

        $this->expectException(EmailAlreadyExists::class);
        $service->execute(new CreateUserCommand('Conductor Uno', 'existe@email.com', null, 'Conductor'));
    }

    #[Test]
    public function propaga_el_duplicado_detectado_por_la_base_de_datos(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())
            ->method('existsByEmail')
            ->willReturn(false);
        $repository->expects($this->once())
            ->method('create')
            ->willThrowException(new EmailAlreadyExists());

        $service = new CreateUserService($repository);

        $this->expectException(EmailAlreadyExists::class);
        $service->execute(new CreateUserCommand('Conductor Uno', 'duplicado@email.com', null, 'Conductor'));
    }
}
