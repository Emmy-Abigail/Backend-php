<?php

namespace Tests\Unit\Application\Service\Auth;

use App\Application\Exception\InvalidCredentials;
use App\Application\Port\In\Auth\LoginCommand;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\IssuedToken;
use App\Application\Port\Out\Security\TokenService;
use App\Application\Service\Auth\LoginService;
use App\Domain\User\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LoginServiceTest extends TestCase
{
    private function makeUser(bool $active = true, string $password = 'clave-correcta'): User
    {
        return new User(
            id: 1,
            names: 'Juan Perez',
            email: 'juan@test.com',
            passwordHash: password_hash($password, PASSWORD_DEFAULT),
            role: 'Admin',
            active: $active,
        );
    }

    #[Test]
    public function test_credenciales_correctas_devuelve_usuario_token_y_expiracion()
    {
        $user = $this->makeUser();
        $issuedToken = new IssuedToken('token-falso', new DateTimeImmutable('+1 hour'));

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByEmail')->with('juan@test.com')->willReturn($user);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->once())
            ->method('issue')
            ->with($user)
            ->willReturn($issuedToken);

        $service = new LoginService($userRepository, $tokenService);
        $result = $service->execute(new LoginCommand('juan@test.com', 'clave-correcta'));

        $this->assertSame($user->id, $result->id);
        $this->assertSame($user->names, $result->names);
        $this->assertSame($user->email, $result->email);
        $this->assertSame($user->role, $result->role);
        $this->assertSame('token-falso', $result->token);
        $this->assertSame('Bearer', $result->tokenType);
        $this->assertSame($issuedToken->expiresAt, $result->expiresAt);
    }

    #[Test]
    public function test_correo_inexistente_lanza_invalid_credentials_sin_generar_token()
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByEmail')->willReturn(null);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('issue');

        $service = new LoginService($userRepository, $tokenService);

        $this->expectException(InvalidCredentials::class);
        $service->execute(new LoginCommand('no-existe@test.com', 'cualquiera'));
    }

    #[Test]
    public function test_contrasena_erronea_lanza_invalid_credentials_sin_generar_token()
    {
        $user = $this->makeUser(password: 'clave-correcta');

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByEmail')->willReturn($user);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('issue');

        $service = new LoginService($userRepository, $tokenService);

        $this->expectException(InvalidCredentials::class);
        $service->execute(new LoginCommand('juan@test.com', 'clave-incorrecta'));
    }

    #[Test]
    public function test_usuario_inactivo_lanza_invalid_credentials_aunque_las_credenciales_sean_correctas()
    {
        $user = $this->makeUser(active: false);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByEmail')->willReturn($user);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('issue');

        $service = new LoginService($userRepository, $tokenService);

        $this->expectException(InvalidCredentials::class);
        $service->execute(new LoginCommand('juan@test.com', 'clave-correcta'));
    }
}
