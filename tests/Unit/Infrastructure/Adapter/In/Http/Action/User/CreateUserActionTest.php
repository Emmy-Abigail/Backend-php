<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\User;

use App\Application\Exception\EmailAlreadyExists;
use App\Application\Port\In\User\CreateUserCommand;
use App\Application\Port\In\User\CreateUserResult;
use App\Application\Port\In\User\CreateUserUseCase;
use App\Infrastructure\Adapter\In\Http\Action\User\CreateUserAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CreateUserActionTest extends TestCase
{
    private function request(mixed $body): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/users');

        return $body === null ? $request : $request->withParsedBody($body);
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    #[Test]
    public function crea_usuario_conductor_con_datos_validos(): void
    {
        $useCase = $this->createMock(CreateUserUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (CreateUserCommand $command): bool => $command->names === 'Juan Pérez'
                && $command->dni === '12345678'
                && $command->email === 'juan@email.com'
                && $command->phone === '999888777'
                && $command->role === 'CONDUCTOR'
                && $command->idTipoVehiculo === 1))
            ->willReturn(new CreateUserResult(2, 'Juan Pérez', '12345678', 'juan@email.com', '999888777', 'CONDUCTOR', null, 1, 'ClaveTemp123@', true));

        $response = (new CreateUserAction($useCase))(
            $this->request([
                'nombres' => '  Juan Pérez  ',
                'dni' => '12345678',
                'correo' => ' JUAN@EMAIL.COM ',
                'telefono' => ' 999888777 ',
                'rol' => 'CONDUCTOR',
                'id_tipo_vehiculo' => 1,
            ]),
            $this->response(),
        );

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Juan Pérez', $body['nombres']);
        self::assertSame('12345678', $body['dni']);
        self::assertTrue($body['correo_enviado']);
    }

    #[Test]
    public function rechaza_nombre_o_telefono_mas_largo_que_la_columna(): void
    {
        $useCase = $this->createMock(CreateUserUseCase::class);
        $useCase->expects($this->never())->method('execute');
        $action = new CreateUserAction($useCase);

        $nameResponse = $action($this->request([
            'nombres' => str_repeat('a', 151),
            'dni' => '12345678',
            'correo' => 'usuario@email.com',
            'rol' => 'CONDUCTOR',
            'id_tipo_vehiculo' => 1,
        ]), $this->response());
        self::assertSame(422, $nameResponse->getStatusCode());

        $phoneResponse = $action($this->request([
            'nombres' => 'Usuario',
            'dni' => '12345678',
            'correo' => 'usuario@email.com',
            'telefono' => str_repeat('1', 21),
            'rol' => 'CONDUCTOR',
            'id_tipo_vehiculo' => 1,
        ]), $this->response());
        self::assertSame(422, $phoneResponse->getStatusCode());
    }

    #[Test]
    public function convierte_el_correo_duplicado_en_conflicto(): void
    {
        $useCase = $this->createStub(CreateUserUseCase::class);
        $useCase->method('execute')->willThrowException(new EmailAlreadyExists());

        $response = (new CreateUserAction($useCase))(
            $this->request([
                'nombres' => 'Usuario',
                'dni' => '12345678',
                'correo' => 'usuario@email.com',
                'rol' => 'CONDUCTOR',
                'id_tipo_vehiculo' => 1,
            ]),
            $this->response(),
        );

        self::assertSame(409, $response->getStatusCode());
    }
}