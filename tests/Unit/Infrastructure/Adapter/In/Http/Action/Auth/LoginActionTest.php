<?php

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Exception\InvalidCredentials;
use App\Application\Port\In\Auth\LoginCommand;
use App\Application\Port\In\Auth\LoginResult;
use App\Application\Port\In\Auth\LoginUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Auth\LoginAction;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class LoginActionTest extends TestCase
{
    private function makeRequest(mixed $parsedBody): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/login');

        return $parsedBody === null ? $request : $request->withParsedBody($parsedBody);
    }

    private function makeResponse(): \Psr\Http\Message\ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    #[Test]
    public function test_json_valido_con_credenciales_validas_devuelve_200_con_la_estructura_correcta()
    {
        $loginResult = new LoginResult(
            1,
            'Juan Perez',
            'juan@test.com',
            'ADMINISTRADOR',
            'token-falso',
            'Bearer',
            new DateTimeImmutable('+1 hour'),
            false,
            null,
            null,
        );

        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->method('execute')->willReturn($loginResult);

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(['correo' => 'juan@test.com', 'password' => 'clave-correcta']), $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertArrayHasKey('token', $body);
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertArrayHasKey('expires_at', $body);
        $this->assertStringContainsString('-05:00', $body['expires_at']);
        $this->assertArrayHasKey('user', $body);
        $this->assertSame('juan@test.com', $body['user']['correo']);
        $this->assertFalse($body['user']['debe_cambiar_password']);
        $this->assertNull($body['user']['sede']);
    }

    #[Test]
    public function test_operador_con_sede_devuelve_objeto_sede_en_el_user()
    {
        $loginResult = new LoginResult(
            2,
            'Operador Sede',
            'operador@test.com',
            'OPERADOR',
            'token-falso',
            'Bearer',
            new DateTimeImmutable('+1 hour'),
            true,
            1,
            'Sede Central Cercado',
        );

        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->method('execute')->willReturn($loginResult);

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(['correo' => 'operador@test.com', 'password' => 'clave-temporal']), $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertTrue($body['user']['debe_cambiar_password']);
        $this->assertNotNull($body['user']['sede']);
        $this->assertSame(1, $body['user']['sede']['id']);
        $this->assertSame('Sede Central Cercado', $body['user']['sede']['nombre']);
    }

    #[Test]
    public function test_body_ausente_o_no_json_devuelve_400()
    {
        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->expects($this->never())->method('execute');

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(null), $this->makeResponse());

        $this->assertSame(400, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('cuerpo_invalido', $body['error']);
    }

    #[Test]
    public function test_credenciales_incorrectas_devuelve_401_con_mensaje_exacto()
    {
        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->method('execute')->willThrowException(new InvalidCredentials());

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(['correo' => 'juan@test.com', 'password' => 'clave-incorrecta']), $this->makeResponse());

        $this->assertSame(401, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('Credenciales incorrectas', $body['message']);
        $this->assertSame('credenciales_incorrectas', $body['error']);
    }

    #[Test]
    public function test_correo_mal_formado_responde_401_credenciales_incorrectas()
    {
        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->expects($this->never())->method('execute');

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(['correo' => 'correo-invalido', 'password' => 'clave']), $this->makeResponse());

        $this->assertSame(401, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('Credenciales incorrectas', $body['message']);
        $this->assertSame('credenciales_incorrectas', $body['error']);
    }

    #[Test]
    public function test_contrasena_vacia_o_mayor_a_72_responde_401_credenciales_incorrectas()
    {
        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->expects($this->never())->method('execute');

        $action = new LoginAction($loginUseCase);

        $vacia = $action($this->makeRequest(['correo' => 'juan@test.com', 'password' => '']), $this->makeResponse());
        $this->assertSame(401, $vacia->getStatusCode());
        $body = $this->decode($vacia);
        $this->assertSame('Credenciales incorrectas', $body['message']);

        $muyLarga = $action($this->makeRequest(['correo' => 'juan@test.com', 'password' => str_repeat('a', 73)]), $this->makeResponse());
        $this->assertSame(401, $muyLarga->getStatusCode());
    }

    #[Test]
    public function test_el_correo_se_normaliza_antes_de_llegar_al_servicio()
    {
        $loginResult = new LoginResult(
            1,
            'Juan Perez',
            'admin@email.com',
            'ADMINISTRADOR',
            'token-falso',
            'Bearer',
            new DateTimeImmutable('+1 hour'),
            false,
            null,
            null,
        );

        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(fn (LoginCommand $command) => $command->email === 'admin@email.com'))
            ->willReturn($loginResult);

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(['correo' => ' ADMIN@EMAIL.COM ', 'password' => 'clave-correcta']), $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
    }
}