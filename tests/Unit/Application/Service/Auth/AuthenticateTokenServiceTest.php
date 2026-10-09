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
            ->willReturn(new TokenClaims(1, 'admin@email.com', 'ADMINISTRADOR', time() + 3600, time() - 60));

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(new User(1, 'Admin', 'admin@email.com', 'hash', 'ADMINISTRADOR', true));

        $service = new AuthenticateTokenService($tokenService, $userRepository);
        $authUser = $service->execute('valid-token');

        self::assertSame(1, $authUser->userId);
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
                'admin@email.com',
                'ADMINISTRADOR',
                $passwordChangedTime->getTimestamp() + 3600,
                $passwordChangedTime->getTimestamp() - 300 // token emitido 5 min antes del cambio
            ));

        $user = new User(1, 'Admin', 'admin@email.com', 'hash', 'ADMINISTRADOR', true);
        $user->passwordChangedAt = $passwordChangedTime;

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
            ->willReturn(new TokenClaims(1, 'user@email.com', 'CONDUCTOR', time() + 3600, time()));

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())
            ->method('findById')
            ->willReturn(new User(1, 'Conductor', 'user@email.com', 'hash', 'CONDUCTOR', false));

        $service = new AuthenticateTokenService($tokenService, $userRepository);

        $this->expectException(UserNotAllowed::class);
        $service->execute('token');
    }
}