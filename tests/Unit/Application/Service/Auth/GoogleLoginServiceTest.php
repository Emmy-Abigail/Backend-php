<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Auth;

use App\Application\Exception\GoogleAccountNotRegistered;
use App\Application\Exception\GoogleIdentityUnavailable;
use App\Application\Exception\InvalidGoogleToken;
use App\Application\Port\In\Auth\GoogleLoginCommand;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\GoogleIdentityVerifier;
use App\Application\Port\Out\Security\IssuedToken;
use App\Application\Port\Out\Security\TokenService;
use App\Application\Service\Auth\GoogleLoginService;
use App\Domain\Catalog\VehicleType;
use App\Domain\User\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GoogleLoginServiceTest extends TestCase
{
    private function driver(bool $active = true, bool $mustChangePassword = true): User
    {
        return new User(
            id: 7,
            names: 'Conductor Uno',
            email: 'conductor@gmail.com',
            passwordHash: password_hash('Temporal123@', PASSWORD_DEFAULT),
            role: 'CONDUCTOR',
            active: $active,
            mustChangePassword: $mustChangePassword,
            idTipoVehiculo: 1,
            placa: 'ABC-123',
        );
    }

    private function verifierReturning(string $email): GoogleIdentityVerifier
    {
        $verifier = $this->createMock(GoogleIdentityVerifier::class);
        $verifier->expects($this->once())->method('verify')->with('id-token-google')->willReturn($email);

        return $verifier;
    }

    #[Test]
    public function un_usuario_registrado_entra_con_la_misma_respuesta_que_el_login_normal(): void
    {
        $user = $this->driver();
        $issuedToken = new IssuedToken('jwt-riva', new DateTimeImmutable('+1 hour'));

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('findByEmail')->with('conductor@gmail.com')->willReturn($user);

        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->once())->method('issue')->with($user)->willReturn($issuedToken);

        $catalog = $this->createStub(CatalogRepository::class);
        $catalog->method('getVehicleTypes')->willReturn([new VehicleType(1, 'MOTO', 'Moto', 1, 20.0, 40.0, 20)]);

        $result = (new GoogleLoginService($this->verifierReturning('conductor@gmail.com'), $users, $tokens, $catalog))
            ->execute(new GoogleLoginCommand('id-token-google'));

        self::assertSame(7, $result->id);
        self::assertSame('CONDUCTOR', $result->role);
        self::assertSame('jwt-riva', $result->token);
        self::assertSame('Bearer', $result->tokenType);
        self::assertSame('Moto', $result->tipoVehiculoNombre);
        self::assertSame('ABC-123', $result->placa);
        // Opcion A: si aun tiene la contrasena temporal, el front lo manda a cambiarla.
        self::assertTrue($result->mustChangePassword);
    }

    #[Test]
    public function un_correo_que_no_registro_el_administrador_no_entra(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findByEmail')->willReturn(null);

        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->never())->method('issue');

        $this->expectException(GoogleAccountNotRegistered::class);
        (new GoogleLoginService($this->verifierReturning('otro@gmail.com'), $users, $tokens))
            ->execute(new GoogleLoginCommand('id-token-google'));
    }

    #[Test]
    public function un_usuario_desactivado_no_entra(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findByEmail')->willReturn($this->driver(active: false));

        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->never())->method('issue');

        $this->expectException(GoogleAccountNotRegistered::class);
        (new GoogleLoginService($this->verifierReturning('conductor@gmail.com'), $users, $tokens))
            ->execute(new GoogleLoginCommand('id-token-google'));
    }

    #[Test]
    public function un_token_invalido_no_consulta_la_base_de_datos(): void
    {
        $verifier = $this->createStub(GoogleIdentityVerifier::class);
        $verifier->method('verify')->willThrowException(new InvalidGoogleToken());

        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('findByEmail');

        $this->expectException(InvalidGoogleToken::class);
        (new GoogleLoginService($verifier, $users, $this->createStub(TokenService::class)))
            ->execute(new GoogleLoginCommand('id-token-google'));
    }

    #[Test]
    public function si_google_no_responde_se_propaga_el_error(): void
    {
        $verifier = $this->createStub(GoogleIdentityVerifier::class);
        $verifier->method('verify')->willThrowException(new GoogleIdentityUnavailable());

        $this->expectException(GoogleIdentityUnavailable::class);
        (new GoogleLoginService($verifier, $this->createStub(UserRepository::class), $this->createStub(TokenService::class)))
            ->execute(new GoogleLoginCommand('id-token-google'));
    }
}
