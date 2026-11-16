<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Security;

use App\Application\Exception\GoogleIdentityUnavailable;
use App\Application\Exception\InvalidGoogleToken;
use App\Infrastructure\Adapter\Out\Http\HttpResponse;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use App\Infrastructure\Adapter\Out\Security\GoogleIdTokenVerifier;
use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Firma tokens con una clave RSA propia publicada como JWKS, igual que hace Google. */
final class GoogleIdTokenVerifierTest extends TestCase
{
    private const CLIENT_ID = '123-abc.apps.googleusercontent.com';

    private static OpenSSLAsymmetricKey $googleKey;
    private static OpenSSLAsymmetricKey $otherKey;
    private ?string $cacheFile = null;

    public static function setUpBeforeClass(): void
    {
        self::$googleKey = self::newKey();
        self::$otherKey = self::newKey();
    }

    protected function tearDown(): void
    {
        if ($this->cacheFile !== null && is_file($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    private static function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('No se pudo generar la clave RSA de prueba');
        }

        return $key;
    }

    private static function jwks(string ...$kids): string
    {
        $rsa = openssl_pkey_get_details(self::$googleKey)['rsa'];
        $keys = array_map(static fn (string $kid) => [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $kid,
            'n' => rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '='),
        ], $kids);

        return json_encode(['keys' => $keys], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $overrides */
    private static function token(array $overrides = [], string $kid = 'kid-1', ?OpenSSLAsymmetricKey $key = null): string
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '1098765',
            'email' => 'Conductor@Gmail.com',
            'email_verified' => true,
            'iat' => time() - 10,
            'exp' => time() + 3600,
        ], $overrides);
        $claims = array_filter($claims, static fn ($value) => $value !== null);

        openssl_pkey_export($key ?? self::$googleKey, $privatePem);

        return JWT::encode($claims, $privatePem, 'RS256', $kid);
    }

    private function transportServing(string $jwks, int $expectedCalls = 1): HttpTransport
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->exactly($expectedCalls))
            ->method('get')
            ->with('https://www.googleapis.com/oauth2/v3/certs', 5.0)
            ->willReturn(new HttpResponse(200, $jwks));

        return $transport;
    }

    private function verifier(HttpTransport $transport, bool $withCache = false): GoogleIdTokenVerifier
    {
        if ($withCache) {
            $this->cacheFile = tempnam(sys_get_temp_dir(), 'riva-google-test-');
            unlink($this->cacheFile);
        }

        return new GoogleIdTokenVerifier($transport, self::CLIENT_ID, $this->cacheFile);
    }

    #[Test]
    public function un_token_valido_de_google_devuelve_el_correo_en_minusculas(): void
    {
        $email = $this->verifier($this->transportServing(self::jwks('kid-1')))->verify(self::token());

        self::assertSame('conductor@gmail.com', $email);
    }

    #[Test]
    public function acepta_el_emisor_sin_https(): void
    {
        $email = $this->verifier($this->transportServing(self::jwks('kid-1')))
            ->verify(self::token(['iss' => 'accounts.google.com']));

        self::assertSame('conductor@gmail.com', $email);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidClaims(): array
    {
        return [
            'token de otra aplicacion (aud)' => [['aud' => 'otra-app.apps.googleusercontent.com']],
            'emisor que no es Google' => [['iss' => 'https://evil.example.com']],
            'token vencido' => [['exp' => time() - 120, 'iat' => time() - 4000]],
            'correo no verificado' => [['email_verified' => false]],
            'sin correo' => [['email' => null]],
            'correo mal formado' => [['email' => 'no-es-un-correo']],
        ];
    }

    #[Test]
    #[DataProvider('invalidClaims')]
    public function rechaza_tokens_con_datos_que_no_corresponden(array $overrides): void
    {
        $this->expectException(InvalidGoogleToken::class);
        $this->verifier($this->transportServing(self::jwks('kid-1')))->verify(self::token($overrides));
    }

    #[Test]
    public function rechaza_un_token_firmado_por_otra_clave_aunque_use_el_mismo_kid(): void
    {
        $this->expectException(InvalidGoogleToken::class);
        $this->verifier($this->transportServing(self::jwks('kid-1')))
            ->verify(self::token([], 'kid-1', self::$otherKey));
    }

    /** @return array<string, array{string}> */
    public static function malformedTokens(): array
    {
        $header = rtrim(strtr(base64_encode('{"alg":"HS256","kid":"kid-1"}'), '+/', '-_'), '=');

        return [
            'texto cualquiera' => ['no-es-un-jwt'],
            'cabecera rota' => ['%%%.e30.firma'],
            'algoritmo HS256' => [$header . '.e30.firma'],
            'demasiado largo' => [str_repeat('a', 8193)],
        ];
    }

    #[Test]
    #[DataProvider('malformedTokens')]
    public function un_token_mal_formado_se_rechaza_sin_consultar_a_google(string $token): void
    {
        $this->expectException(InvalidGoogleToken::class);
        $this->verifier($this->transportServing(self::jwks('kid-1'), 0))->verify($token);
    }

    #[Test]
    public function un_kid_desconocido_vuelve_a_pedir_las_claves_una_sola_vez(): void
    {
        $this->expectException(InvalidGoogleToken::class);
        $this->verifier($this->transportServing(self::jwks('kid-1'), 2))
            ->verify(self::token([], 'kid-que-no-existe'));
    }

    #[Test]
    public function usa_la_cache_y_la_renueva_cuando_google_rota_sus_claves(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, self::jwks('kid-1')),
                new HttpResponse(200, self::jwks('kid-1', 'kid-2')),
            );
        $verifier = $this->verifier($transport, true);

        // 1.a llamada: descarga las claves. 2.a: las toma de la cache.
        self::assertSame('conductor@gmail.com', $verifier->verify(self::token()));
        self::assertSame('conductor@gmail.com', $verifier->verify(self::token()));
        // Clave nueva (kid-2): renueva la cache una vez.
        self::assertSame('conductor@gmail.com', $verifier->verify(self::token([], 'kid-2')));
    }

    /** @return array<string, array{HttpResponse|null}> */
    public static function certsFailures(): array
    {
        return [
            'error de red' => [null],
            'error 500' => [new HttpResponse(500, 'error')],
            'json sin claves' => [new HttpResponse(200, '{"keys": []}')],
            'json roto' => [new HttpResponse(200, '<html>')],
        ];
    }

    #[Test]
    #[DataProvider('certsFailures')]
    public function si_no_se_obtienen_las_claves_de_google_lanza_no_disponible(?HttpResponse $response): void
    {
        $transport = $this->createStub(HttpTransport::class);
        if ($response === null) {
            $transport->method('get')->willThrowException(new RuntimeException('timeout'));
        } else {
            $transport->method('get')->willReturn($response);
        }

        $this->expectException(GoogleIdentityUnavailable::class);
        $this->verifier($transport)->verify(self::token());
    }
}
