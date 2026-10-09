<?php

namespace Tests\Unit\Application\Service\Auth;

use App\Application\Exception\InvalidCurrentPassword;
use App\Application\Exception\SamePasswordException;
use App\Application\Exception\UserNotAllowed;
use App\Application\Exception\WeakPasswordException;
use App\Application\Port\In\Auth\ChangePasswordCommand;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\IssuedToken;
use App\Application\Port\Out\Security\TokenService;
use App\Application\Service\Auth\ChangePasswordService;
use App\Domain\User\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ChangePasswordServiceTest extends TestCase
{
    #[Test]
    public function test_cambio_exitoso_actualiza_password_y_emite_nuevo_token(): void
    {
        $oldHash = password_hash('ClaveActual2026#', PASSWORD_DEFAULT);
        $user = new User(1, 'Juan Perez', 'juan@test.com', $oldHash, 'CONDUCTOR', true);
        $updatedUser = new User(1, 'Juan Perez', 'juan@test.com', password_hash('NuevaClave2026@', PASSWORD_DEFAULT), 'CONDUCTOR', true);

        $issuedToken = new IssuedToken('nuevo-token-jwt', new DateTimeImmutable('+8 hours'));

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->exactly(2))
            ->method('findById')
            ->with(1)
            ->willReturnOnConsecutiveCalls($user, $updatedUser);

        $userRepository->expects($this->once())
            ->method('updatePassword')
            ->with(1, $this->callback(fn (string $hash) => password_verify('NuevaClave2026@', $hash)), false);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->once())
            ->method('issue')
            ->with($updatedUser)
            ->willReturn($issuedToken);

        $service = new ChangePasswordService($userRepository, $tokenService);
        $result = $service->execute(new ChangePasswordCommand(1, 'ClaveActual2026#', 'NuevaClave2026@'));

        $this->assertSame('Contraseña actualizada exitosamente', $result->message);
        $this->assertSame('nuevo-token-jwt', $result->token);
        $this->assertSame('Bearer', $result->tokenType);
    }

    #[Test]
    public function test_password_actual_incorrecta_lanza_excepcion(): void
    {
        $oldHash = password_hash('ClaveCorrecta2026#', PASSWORD_DEFAULT);
        $user = new User(1, 'Juan Perez', 'juan@test.com', $oldHash, 'CONDUCTOR', true);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->willReturn($user);

        $tokenService = $this->createMock(TokenService::class);

        $service = new ChangePasswordService($userRepository, $tokenService);

        $this->expectException(InvalidCurrentPassword::class);
        $service->execute(new ChangePasswordCommand(1, 'ClaveEquivocada', 'NuevaClave2026@'));
    }

    #[Test]
    public function test_misma_password_lanza_excepcion(): void
    {
        $oldHash = password_hash('MismaClave2026#', PASSWORD_DEFAULT);
        $user = new User(1, 'Juan Perez', 'juan@test.com', $oldHash, 'CONDUCTOR', true);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->willReturn($user);

        $tokenService = $this->createMock(TokenService::class);

        $service = new ChangePasswordService($userRepository, $tokenService);

        $this->expectException(SamePasswordException::class);
        $service->execute(new ChangePasswordCommand(1, 'MismaClave2026#', 'MismaClave2026#'));
    }

    #[Test]
    public function test_password_debil_lanza_excepcion(): void
    {
        $oldHash = password_hash('ClaveActual2026#', PASSWORD_DEFAULT);
        $user = new User(1, 'Juan Perez', 'juan@test.com', $oldHash, 'CONDUCTOR', true);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->willReturn($user);

        $tokenService = $this->createMock(TokenService::class);

        $service = new ChangePasswordService($userRepository, $tokenService);

        $this->expectException(WeakPasswordException::class);
        $service->execute(new ChangePasswordCommand(1, 'ClaveActual2026#', 'debil'));
    }
}