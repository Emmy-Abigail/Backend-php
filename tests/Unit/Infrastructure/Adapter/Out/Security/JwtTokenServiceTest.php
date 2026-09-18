<?php

namespace Tests\Unit\Infrastructure\Adapter\Out\Security;

use App\Application\Exception\InvalidToken;
use App\Application\Exception\TokenExpired;
use App\Application\Port\Out\Security\TokenClaims;
use App\Domain\User\User;
use App\Infrastructure\Adapter\Out\Security\JwtTokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class JwtTokenServiceTest extends TestCase
{
    private const SECRET = '12345678901234567890123456789012'; // 32 caracteres
    private const ISSUER = 'sistema-envios';
    private const TTL = 3600;

    private function makeService(string $secret = self::SECRET, string $issuer = self::ISSUER, int $ttl = self::TTL): JwtTokenService
    {
        return new JwtTokenService($secret, $issuer, $ttl);
    }

    private function makeUser(): User
    {
        return new User(
            id: 1,
            names: 'Juan Perez',
            email: 'juan@test.com',
            passwordHash: password_hash('secret', PASSWORD_DEFAULT),
            role: 'Admin',
            active: true,
        );
    }

    #[Test]
    public function test_al_emitir_crea_un_jwt_hs256_con_los_claims_requeridos()
    {
        $service = $this->makeService();
        $user = $this->makeUser();

        $issued = $service->issue($user);

        [$headerB64] = explode('.', $issued->token);
        $header = json_decode(base64_decode($headerB64), true);
        $this->assertSame('HS256', $header['alg']);

        $decoded = (array) JWT::decode($issued->token, new Key(self::SECRET, 'HS256'));

        $this->assertSame(self::ISSUER, $decoded['iss']);
        $this->assertSame((string) $user->id, $decoded['sub']);
        $this->assertSame($user->email, $decoded['correo']);
        $this->assertSame($user->names, $decoded['nombre']);
        $this->assertSame($user->role, $decoded['rol']);
        $this->assertArrayHasKey('iat', $decoded);
        $this->assertArrayHasKey('exp', $decoded);
        $this->assertArrayHasKey('jti', $decoded);
    }

    #[Test]
    public function test_puede_verificarse_correctamente_con_el_mismo_secreto()
    {
        $service = $this->makeService();
        $user = $this->makeUser();

        $issued = $service->issue($user);
        $claims = $service->verify($issued->token);

        $this->assertInstanceOf(TokenClaims::class, $claims);
        $this->assertSame($user->id, $claims->userId);
        $this->assertSame($user->role, $claims->role);
    }

    #[Test]
    public function test_falla_si_el_token_esta_expirado()
    {
        $service = $this->makeService(ttl: -10); // exp queda en el pasado
        $issued = $service->issue($this->makeUser());

        $this->expectException(TokenExpired::class);
        $service->verify($issued->token);
    }

    #[Test]
    public function test_falla_si_esta_firmado_con_otro_secreto()
    {
        $emisor = $this->makeService(secret: self::SECRET);
        $verificador = $this->makeService(secret: '99999999999999999999999999999999');

        $issued = $emisor->issue($this->makeUser());

        $this->expectException(InvalidToken::class);
        $verificador->verify($issued->token);
    }

    #[Test]
    public function test_falla_si_el_issuer_es_distinto()
    {
        $emisor = $this->makeService(issuer: 'issuer-a');
        $verificador = $this->makeService(issuer: 'issuer-b');

        $issued = $emisor->issue($this->makeUser());

        $this->expectException(InvalidToken::class);
        $verificador->verify($issued->token);
    }

    #[Test]
    public function test_falla_si_el_formato_del_token_es_invalido()
    {
        $service = $this->makeService();

        $this->expectException(InvalidToken::class);
        $service->verify('esto-no-es-un-jwt');
    }

    #[Test]
    public function test_rechaza_un_secreto_menor_a_32_caracteres()
    {
        $this->expectException(InvalidArgumentException::class);

        new JwtTokenService('secreto_corto', self::ISSUER, self::TTL);
    }
}
