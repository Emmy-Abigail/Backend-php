<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Auth;

use App\Application\Exception\InvalidToken;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\TokenClaims;
use App\Application\Port\Out\Security\TokenService;
use App\Application\Service\Auth\AuthenticateTokenService;
use App\Domain\User\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthenticateTokenServiceTest extends TestCase
{
    #[Test]
    public function autentica_correctamente_a_un_usuario_activo(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->once())
            ->method('verify')
            ->with('valid-token')
            ->willReturn(new TokenClaims(1, 'ADMINISTRADOR', null, time() - 60));

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(new User(1, 'Admin', 'admin@email.com', 'hash', 'ADMINISTRADOR', true));

        $service = new AuthenticateTokenService($tokenService, $userRepository);
        $authUser = $service->execute('valid-token');

        self::assertSame(1, $authUser->id);
        self::assertSame('Admin', $authUser->names);
        self::assertSame('admin@email.com', $authUser->email);
        self::assertSame('ADMINISTRADOR', $authUser->role);
    }

    #[Test]
    public function rechaza_token_emitido_antes_de_cambio_de_contrasena(): void
    {
        $passwordChangedTime = new DateTimeImmutable('2026-10-06 12:00:00');

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->once())
            ->method('verify')
            ->with('old-token')
            ->willReturn(new TokenClaims(
                1,
                'ADMINISTRADOR',
                null,
                $passwordChangedTime->getTimestamp() - 300 // emitido 5 min antes del cambio
            ));

        $user = new User(
            id: 1,
            names: 'Admin',
            email: 'admin@email.com',
            passwordHash: 'hash',
            role: 'ADMINISTRADOR',
            active: true,
            passwordChangedAt: $passwordChangedTime
        );

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($user);

        $service = new AuthenticateTokenService($tokenService, $userRepository);

        $this->expectException(InvalidToken::class);
        $service->execute('old-token');
    }

    #[Test]
    public function rechaza_usuario_inactivo(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->once())
            ->method('verify')
            ->willReturn(new TokenClaims(1, 'CONDUCTOR', issuedAt: time()));

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->willReturn(new User(1, 'Conductor', 'user@email.com', 'hash', 'CONDUCTOR', false));

        $service = new AuthenticateTokenService($tokenService, $userRepository);

        $this->expectException(UserNotAllowed::class);
        $service->execute('token');
    }

    #[Test]
    public function acepta_token_nuevo_y_lee_indicador_desde_base_de_datos(): void
    {
        $changedAt = new DateTimeImmutable('2026-10-09T12:00:00+00:00');
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->once())->method('verify')->with('new-token')
            ->willReturn(new TokenClaims(1, 'CONDUCTOR', issuedAt: $changedAt->getTimestamp()));
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())->method('findById')->with(1)
            ->willReturn(new User(1, 'Juan', 'juan@test.com', 'hash', 'CONDUCTOR', true,
                mustChangePassword: false, passwordChangedAt: $changedAt));

        $authenticated = (new AuthenticateTokenService($tokens, $repository))->execute('new-token');

        self::assertSame(1, $authenticated->id);
        self::assertFalse($authenticated->mustChangePassword);
    }

    #[Test]
    public function devuelve_indicador_obligatorio_desde_base_de_datos(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->once())->method('verify')->willReturn(new TokenClaims(1, 'CONDUCTOR'));
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())->method('findById')->with(1)
            ->willReturn(new User(1, 'Juan', 'juan@test.com', 'hash', 'CONDUCTOR', true, mustChangePassword: true));

        self::assertTrue((new AuthenticateTokenService($tokens, $repository))->execute('token')->mustChangePassword);
    }

    #[Test]
    public function rechaza_usuario_inexistente(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->once())->method('verify')->willReturn(new TokenClaims(1, 'CONDUCTOR'));
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())->method('findById')->with(1)->willReturn(null);

        $this->expectException(UserNotAllowed::class);
        (new AuthenticateTokenService($tokens, $repository))->execute('token');
    }

    #[Test]
    public function test_token_invalido_propaga_la_excepcion_sin_consultar_el_repositorio(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->once())
            ->method('verify')
            ->with('token-malformado')
            ->willThrowException(new InvalidToken());

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->never())->method('findById');

        $service = new AuthenticateTokenService($tokenService, $userRepository);

        $this->expectException(InvalidToken::class);
        $service->execute('token-malformado');
    }
}
