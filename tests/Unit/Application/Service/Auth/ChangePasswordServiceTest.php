<?php

namespace Tests\Unit\Application\Service\Auth;

use App\Application\Exception\InvalidCurrentPassword;
use App\Application\Exception\MissingCurrentPassword;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChangePasswordServiceTest extends TestCase
{
    #[Test]
    #[DataProvider('mandatoryChangePasswords')]
    public function primer_cambio_ignora_password_actual_y_emite_token(?string $currentPassword): void
    {
        $user = new User(
            1, 'Juan Perez', 'juan@test.com', password_hash('TemporalReal2026@', PASSWORD_DEFAULT),
            'CONDUCTOR', true, mustChangePassword: true,
        );
        $updatedUser = new User(
            1, 'Juan Perez', 'juan@test.com', password_hash('NuevaClave2026@', PASSWORD_DEFAULT),
            'CONDUCTOR', true, mustChangePassword: false,
            passwordChangedAt: new DateTimeImmutable('2026-10-09T12:00:00+00:00'),
        );
        $issuedToken = new IssuedToken('nuevo-token-jwt', new DateTimeImmutable('2026-10-09T20:00:00+00:00'));
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->exactly(2))->method('findById')->with(1)
            ->willReturnOnConsecutiveCalls($user, $updatedUser);
        $repository->expects($this->once())->method('updatePassword')
            ->with(1, $this->callback(static fn (string $hash): bool =>
                $hash !== 'NuevaClave2026@' && password_verify('NuevaClave2026@', $hash)), false);
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->once())->method('issue')->with($updatedUser)->willReturn($issuedToken);

        $result = (new ChangePasswordService($repository, $tokens))->execute(
            new ChangePasswordCommand(1, $currentPassword, 'NuevaClave2026@'),
        );

        self::assertFalse($updatedUser->mustChangePassword);
        self::assertSame($issuedToken->token, $result->token);
        self::assertSame('Bearer', $result->tokenType);
        self::assertSame($issuedToken->expiresAt, $result->expiresAt);
    }

    public static function mandatoryChangePasswords(): array
    {
        return [
            'sin password_actual' => [null],
            'password_actual incorrecta del frontend' => ['Temporal123!'],
            'password_actual vacia' => [''],
            'password_actual coincide con nueva pero no con almacenada' => ['NuevaClave2026@'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedChanges')]
    public function rechaza_cambios_invalidos_sin_guardar_ni_emitir_token(
        bool $mustChangePassword, ?string $currentPassword, string $newPassword, string $exception,
    ): void {
        $user = new User(
            1, 'Juan Perez', 'juan@test.com', password_hash('TemporalReal2026@', PASSWORD_DEFAULT),
            'CONDUCTOR', true, mustChangePassword: $mustChangePassword,
        );
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())->method('findById')->with(1)->willReturn($user);
        $repository->expects($this->never())->method('updatePassword');
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->never())->method('issue');

        $this->expectException($exception);
        (new ChangePasswordService($repository, $tokens))->execute(
            new ChangePasswordCommand(1, $currentPassword, $newPassword),
        );
    }

    public static function rejectedChanges(): array
    {
        return [
            'normal sin actual' => [false, null, 'NuevaClave2026@', MissingCurrentPassword::class],
            'normal actual vacia' => [false, '', 'NuevaClave2026@', MissingCurrentPassword::class],
            'normal actual incorrecta' => [false, 'Temporal123!', 'NuevaClave2026@', InvalidCurrentPassword::class],
            'primer cambio nueva debil' => [true, null, 'debil', WeakPasswordException::class],
            'primer cambio nueva vacia' => [true, null, '', WeakPasswordException::class],
            'primer cambio misma almacenada sin actual' => [true, null, 'TemporalReal2026@', SamePasswordException::class],
            'primer cambio misma almacenada con actual incorrecta' => [true, 'Temporal123!', 'TemporalReal2026@', SamePasswordException::class],
            'normal nueva debil' => [false, 'TemporalReal2026@', 'debil', WeakPasswordException::class],
            'normal misma almacenada' => [false, 'TemporalReal2026@', 'TemporalReal2026@', SamePasswordException::class],
        ];
    }

    #[Test]
    #[DataProvider('unavailableUsers')]
    public function rechaza_usuario_inexistente_o_inactivo(?User $user): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())->method('findById')->with(1)->willReturn($user);
        $repository->expects($this->never())->method('updatePassword');
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->never())->method('issue');

        $this->expectException(UserNotAllowed::class);
        (new ChangePasswordService($repository, $tokens))->execute(
            new ChangePasswordCommand(1, null, 'NuevaClave2026@'),
        );
    }

    public static function unavailableUsers(): array
    {
        return [
            'inexistente' => [null],
            'inactivo con cambio obligatorio' => [new User(1, 'Juan', 'juan@test.com', 'hash', 'CONDUCTOR', false, mustChangePassword: true)],
        ];
    }

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
        $userRepository->expects($this->once())->method('findById')->willReturn($user);
        $userRepository->expects($this->never())->method('updatePassword');

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('issue');

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
        $userRepository->expects($this->once())->method('findById')->willReturn($user);
        $userRepository->expects($this->never())->method('updatePassword');

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('issue');

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
        $userRepository->expects($this->once())->method('findById')->willReturn($user);
        $userRepository->expects($this->never())->method('updatePassword');

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('issue');

        $service = new ChangePasswordService($userRepository, $tokenService);

        $this->expectException(WeakPasswordException::class);
        $service->execute(new ChangePasswordCommand(1, 'ClaveActual2026#', 'debil'));
    }
}
