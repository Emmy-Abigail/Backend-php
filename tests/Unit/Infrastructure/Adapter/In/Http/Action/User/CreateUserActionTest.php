<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\User;

use App\Application\Exception\EmailAlreadyExists;
use App\Application\Exception\PlacaAlreadyExists;
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

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function conductorBody(array $overrides = []): array
    {
        return array_merge([
            'nombres' => 'Juan Pérez',
            'dni' => '12345678',
            'correo' => 'juan@email.com',
            'telefono' => '999888777',
            'rol' => 'CONDUCTOR',
            'id_tipo_vehiculo' => 2, // 2 = AUTO
            'placa' => 'ABC-123',
        ], $overrides);
    }

    private function useCaseThatNeverRuns(): CreateUserUseCase
    {
        $useCase = $this->createMock(CreateUserUseCase::class);
        $useCase->expects($this->never())->method('execute');

        return $useCase;
    }

    #[Test]
    public function crea_conductor_auto_normalizando_correo_telefono_y_placa(): void
    {
        $useCase = $this->createMock(CreateUserUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (CreateUserCommand $command): bool => $command->names === 'Juan Pérez'
                && $command->dni === '12345678'
                && $command->email === 'juan@email.com'
                && $command->phone === '999888777'
                && $command->role === 'CONDUCTOR'
                && $command->idSede === null
                && $command->idTipoVehiculo === 2
                && $command->placa === 'ABC123'))
            ->willReturn(new CreateUserResult(2, 'Juan Pérez', '12345678', 'juan@email.com', 'CONDUCTOR', null, 2, 'ClaveTemp123@', true, 'ABC123'));

        $response = (new CreateUserAction($useCase))(
            $this->request($this->conductorBody([
                'nombres' => '  Juan Pérez  ',
                'correo' => ' JUAN@EMAIL.COM ',
                'telefono' => ' 999888777 ',
                'placa' => ' abc-123 ',
            ])),
            $this->response(),
        );

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Juan Pérez', $body['nombres']);
        self::assertSame('12345678', $body['dni']);
        self::assertSame('ABC123', $body['placa']);
        self::assertTrue($body['correo_enviado']);
    }

    #[Test]
    public function crea_conductor_motorizado_con_placa_de_moto(): void
    {
        $useCase = $this->createMock(CreateUserUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (CreateUserCommand $command): bool => $command->idTipoVehiculo === 1
                && $command->placa === 'AB5555'))
            ->willReturn(new CreateUserResult(3, 'Carlos Moto', '87654321', 'carlos@email.com', 'CONDUCTOR', null, 1, 'ClaveTemp123@', true, 'AB5555'));

        $response = (new CreateUserAction($useCase))(
            $this->request($this->conductorBody([
                'id_tipo_vehiculo' => 1,
                'placa' => 'AB-5555',
            ])),
            $this->response(),
        );

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function rechaza_dni_que_no_tenga_8_digitos_numericos(): void
    {
        $action = new CreateUserAction($this->useCaseThatNeverRuns());

        foreach (['1234567', '123456789', '1234567A', ''] as $dni) {
            $response = $action($this->request($this->conductorBody(['dni' => $dni])), $this->response());
            self::assertSame(422, $response->getStatusCode());
        }
    }

    #[Test]
    public function rechaza_telefono_que_no_tenga_9_digitos_o_no_empiece_con_9(): void
    {
        $action = new CreateUserAction($this->useCaseThatNeverRuns());

        foreach (['99988877', '9998887771', '899888777', '99988877a'] as $phone) {
            $response = $action($this->request($this->conductorBody(['telefono' => $phone])), $this->response());
            self::assertSame(422, $response->getStatusCode());
        }
    }

    #[Test]
    public function rechaza_placa_invalida_segun_tipo_de_vehiculo(): void
    {
        $action = new CreateUserAction($this->useCaseThatNeverRuns());

        // Para AUTO (tipo 2), debe tener 3 letras y 3 números
        $resAuto = $action($this->request($this->conductorBody(['id_tipo_vehiculo' => 2, 'placa' => 'AB-5555'])), $this->response());
        self::assertSame(422, $resAuto->getStatusCode());

        // Para MOTORIZADO (tipo 1), debe tener formato de moto
        $resMoto = $action($this->request($this->conductorBody(['id_tipo_vehiculo' => 1, 'placa' => 'ABC-123'])), $this->response());
        self::assertSame(422, $resMoto->getStatusCode());
    }

    #[Test]
    public function rechaza_placa_para_operador(): void
    {
        $action = new CreateUserAction($this->useCaseThatNeverRuns());

        $response = $action($this->request([
            'nombres' => 'Operador',
            'dni' => '87654321',
            'correo' => 'operador@email.com',
            'rol' => 'OPERADOR',
            'id_sede' => 1,
            'placa' => 'ABC-123',
        ]), $this->response());

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function convierte_la_placa_duplicada_en_conflicto(): void
    {
        $useCase = $this->createStub(CreateUserUseCase::class);
        $useCase->method('execute')->willThrowException(new PlacaAlreadyExists());

        $response = (new CreateUserAction($useCase))($this->request($this->conductorBody()), $this->response());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('placa_duplicada', json_decode((string) $response->getBody(), true)['error']);
    }

    #[Test]
    public function convierte_el_correo_duplicado_en_conflicto(): void
    {
        $useCase = $this->createStub(CreateUserUseCase::class);
        $useCase->method('execute')->willThrowException(new EmailAlreadyExists());

        $response = (new CreateUserAction($useCase))($this->request($this->conductorBody()), $this->response());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('correo_duplicado', json_decode((string) $response->getBody(), true)['error']);
    }
}