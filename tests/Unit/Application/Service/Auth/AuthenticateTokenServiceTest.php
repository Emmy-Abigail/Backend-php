<?php

namespace Tests\Unit\Application\Service\Auth;

use App\Application\Exception\InvalidToken;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\TokenClaims;
use App\Application\Port\Out\Security\TokenService;
use App\Application\Service\Auth\AuthenticateTokenService;
use App\Domain\User\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AuthenticateTokenServiceTest extends TestCase
{
    private function makeUser(bool $active = true): User
    {
        return new User(
            id: 1,
            names: 'Juan Perez',
            email: 'juan@test.com',
            passwordHash: password_hash('clave-correcta', PASSWORD_DEFAULT),
            role: 'Conductor',
            active: $active,
        );
    }

    #[Test]
    public function test_token_valido_de_usuario_activo_devuelve_authenticated_user()
    {
        $user = $this->makeUser(active: true);
        $claims = new TokenClaims($user->id, $user->role);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('verify')->with('token-valido')->willReturn($claims);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->with(1)->willReturn($user);

        $service = new AuthenticateTokenService($tokenService, $userRepository);
        $result = $service->execute('token-valido');

        $this->assertSame($user->id, $result->id);
        $this->assertSame($user->names, $result->names);
        $this->assertSame($user->email, $result->email);
        $this->assertSame($user->role, $result->role);
    }

    #[Test]
    public function test_usuario_desactivado_lanza_user_not_allowed_aunque_el_token_siga_vigente()
    {
        // Simula el escenario real: el usuario tenía sesión abierta (token aún
        // no expirado) pero un Administrador lo desactivó mientras tanto.
        $user = $this->makeUser(active: false);
        $claims = new TokenClaims($user->id, $user->role);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('verify')->willReturn($claims);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->with(1)->willReturn($user);

        $service = new AuthenticateTokenService($tokenService, $userRepository);

        $this->expectException(UserNotAllowed::class);
        $service->execute('token-valido-pero-usuario-desactivado');
    }

    #[Test]
    public function test_usuario_eliminado_lanza_user_not_allowed()
    {
        $claims = new TokenClaims(999, 'Conductor');

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('verify')->willReturn($claims);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->with(999)->willReturn(null);

        $service = new AuthenticateTokenService($tokenService, $userRepository);

        $this->expectException(UserNotAllowed::class);
        $service->execute('token-de-usuario-inexistente');
    }

    #[Test]
    public function test_token_invalido_propaga_la_excepcion_sin_consultar_el_repositorio()
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('verify')->willThrowException(new InvalidToken());

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->never())->method('findById');

        $service = new AuthenticateTokenService($tokenService, $userRepository);

        $this->expectException(InvalidToken::class);
        $service->execute('token-malformado');
    }
}