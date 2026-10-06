<?php

namespace Tests\Unit\Application\Service\Auth;

use App\Application\Exception\InvalidCredentials;
use App\Application\Port\In\Auth\LoginCommand;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\IssuedToken;
use App\Application\Port\Out\Security\TokenService;
use App\Application\Service\Auth\LoginService;
use App\Domain\Catalog\Sede;
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
            role: 'ADMINISTRADOR',
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
        $this->assertNull($result->idSede);
        $this->assertNull($result->sedeNombre);
    }

    #[Test]
    public function test_operador_con_sede_devuelve_sede_resuelta()
    {
        $user = new User(
            id: 2,
            names: 'Operador Test',
            email: 'operador@test.com',
            passwordHash: password_hash('clave-correcta', PASSWORD_DEFAULT),
            role: 'OPERADOR',
            active: true,
            phone: null,
            mustChangePassword: true,
            dni: '12345678',
            idSede: 1,
        );
        $issuedToken = new IssuedToken('token-falso', new DateTimeImmutable('+1 hour'));

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByEmail')->willReturn($user);

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('issue')->willReturn($issuedToken);

        $sede = new Sede(
            id: 1,
            nombre: 'Sede Central Cercado',
            idZona: 1,
            zonaCodigo: 'A',
            zonaNombre: 'Zona A',
            ubigeoDistrito: '150101',
            distritoNombre: 'Lima',
            direccion: 'Av. Principal 123',
            lat: -12.0464,
            lng: -77.0428,
            activa: true,
        );

        $catalogRepo = $this->createMock(CatalogRepository::class);
        $catalogRepo->method('getSedes')->willReturn([$sede]);

        $service = new LoginService($userRepository, $tokenService, $catalogRepo);
        $result = $service->execute(new LoginCommand('operador@test.com', 'clave-correcta'));

        $this->assertSame(1, $result->idSede);
        $this->assertSame('Sede Central Cercado', $result->sedeNombre);
        $this->assertTrue($result->mustChangePassword);
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