<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Exception\GoogleAccountNotRegistered;
use App\Application\Exception\GoogleIdentityUnavailable;
use App\Application\Exception\InvalidGoogleToken;
use App\Application\Port\In\Auth\GoogleLoginCommand;
use App\Application\Port\In\Auth\GoogleLoginUseCase;
use App\Application\Port\In\Auth\LoginResult;
use App\Infrastructure\Adapter\In\Http\Action\Auth\GoogleLoginAction;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Throwable;

final class GoogleLoginActionTest extends TestCase
{
    private function call(GoogleLoginUseCase $useCase, mixed $body): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/auth/google');
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return (new GoogleLoginAction($useCase))($request, (new ResponseFactory())->createResponse());
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    #[Test]
    public function devuelve_el_mismo_json_que_el_login_normal(): void
    {
        $useCase = $this->createMock(GoogleLoginUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(fn (GoogleLoginCommand $command) => $command->idToken === 'id-token'))
            ->willReturn(new LoginResult(
                7, 'Conductor Uno', 'conductor@gmail.com', 'CONDUCTOR', 'jwt-riva', 'Bearer',
                new DateTimeImmutable('+1 hour'), true, null, null, 1, 'Moto', 20.0, 40.0, 'ABC-123',
            ));

        $response = $this->call($useCase, ['id_token' => ' id-token ']);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('jwt-riva', $body['token']);
        self::assertSame('Bearer', $body['token_type']);
        self::assertStringContainsString('-05:00', $body['expires_at']);
        self::assertSame('conductor@gmail.com', $body['user']['correo']);
        self::assertSame('CONDUCTOR', $body['user']['rol']);
        self::assertTrue($body['user']['debe_cambiar_password']);
        self::assertSame(1, $body['user']['vehiculo']['id']);
        self::assertSame('Moto', $body['user']['vehiculo']['nombre']);
        self::assertEquals(20, $body['user']['vehiculo']['peso_max_total_kg']);
        self::assertEquals(40, $body['user']['vehiculo']['lado_max_cm']);
        self::assertSame('ABC-123', $body['user']['placa']);
    }

    #[Test]
    public function un_cuerpo_que_no_es_json_responde_400(): void
    {
        $useCase = $this->createMock(GoogleLoginUseCase::class);
        $useCase->expects($this->never())->method('execute');

        $response = $this->call($useCase, null);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('cuerpo_invalido', $this->decode($response)['error']);
    }

    /** @return array<string, array{mixed}> */
    public static function badTokens(): array
    {
        return [
            'sin id_token' => [[]],
            'vacio' => [['id_token' => '   ']],
            'no es texto' => [['id_token' => 123]],
            'demasiado largo' => [['id_token' => str_repeat('a', 8193)]],
        ];
    }

    #[Test]
    #[DataProvider('badTokens')]
    public function un_id_token_ausente_o_mal_formado_responde_401_sin_llamar_al_servicio(mixed $body): void
    {
        $useCase = $this->createMock(GoogleLoginUseCase::class);
        $useCase->expects($this->never())->method('execute');

        $response = $this->call($useCase, $body);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('google_token_invalido', $this->decode($response)['error']);
    }

    /** @return array<string, array{Throwable, int, string}> */
    public static function failures(): array
    {
        return [
            'token invalido' => [new InvalidGoogleToken(), 401, 'google_token_invalido'],
            'correo no registrado' => [new GoogleAccountNotRegistered(), 401, 'cuenta_no_registrada'],
            'google caido' => [new GoogleIdentityUnavailable(), 503, 'google_no_disponible'],
        ];
    }

    #[Test]
    #[DataProvider('failures')]
    public function traduce_los_errores_del_servicio(Throwable $error, int $status, string $code): void
    {
        $useCase = $this->createStub(GoogleLoginUseCase::class);
        $useCase->method('execute')->willThrowException($error);

        $response = $this->call($useCase, ['id_token' => 'id-token']);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, $this->decode($response)['error']);
    }
}
