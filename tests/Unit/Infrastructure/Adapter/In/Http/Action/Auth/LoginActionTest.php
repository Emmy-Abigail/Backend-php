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
        $loginResult = new LoginResult(1, 'Juan Perez', 'juan@test.com', 'Admin', 'token-falso', 'Bearer', new DateTimeImmutable('+1 hour'));

        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->method('execute')->willReturn($loginResult);

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(['correo' => 'juan@test.com', 'password' => 'clave-correcta']), $this->makeResponse());

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertArrayHasKey('token', $body);
        $this->assertArrayHasKey('token_type', $body);
        $this->assertArrayHasKey('expires_at', $body);
        $this->assertArrayHasKey('user', $body);
        $this->assertSame('juan@test.com', $body['user']['correo']);
    }

    #[Test]
    public function test_body_ausente_o_no_json_devuelve_400()
    {
        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->expects($this->never())->method('execute');

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(null), $this->makeResponse());

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function test_falta_correo_o_password_devuelve_400()
    {
        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->expects($this->never())->method('execute');

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(['correo' => 'juan@test.com']), $this->makeResponse());

        $this->assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function test_correo_invalido_devuelve_422()
    {
        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->expects($this->never())->method('execute');

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(['correo' => 'no-es-un-correo', 'password' => 'clave']), $this->makeResponse());

        $this->assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function test_contrasena_vacia_o_con_mas_de_72_caracteres_devuelve_422()
    {
        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->expects($this->never())->method('execute');
        $action = new LoginAction($loginUseCase);

        $vacia = $action($this->makeRequest(['correo' => 'juan@test.com', 'password' => '']), $this->makeResponse());
        $this->assertSame(422, $vacia->getStatusCode());

        $muyLarga = $action($this->makeRequest(['correo' => 'juan@test.com', 'password' => str_repeat('a', 73)]), $this->makeResponse());
        $this->assertSame(422, $muyLarga->getStatusCode());
    }

    #[Test]
    public function test_credenciales_incorrectas_devuelve_401_con_mensaje()
    {
        $loginUseCase = $this->createMock(LoginUseCase::class);
        $loginUseCase->method('execute')->willThrowException(new InvalidCredentials());

        $action = new LoginAction($loginUseCase);
        $response = $action($this->makeRequest(['correo' => 'juan@test.com', 'password' => 'clave-incorrecta']), $this->makeResponse());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Credenciales inválidas'], $this->decode($response));
    }

    #[Test]
    public function test_el_correo_se_normaliza_antes_de_llegar_al_servicio()
    {
        $loginResult = new LoginResult(1, 'Juan Perez', 'admin@email.com', 'Admin', 'token-falso', 'Bearer', new DateTimeImmutable('+1 hour'));

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
