<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Batch;

use App\Application\Exception\BatchAccessDenied;
use App\Application\Exception\BatchHasNoPackages;
use App\Application\Exception\BatchNotFound;
use App\Application\Exception\InvalidBatchTransition;
use App\Application\Exception\RouteStartConflict;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\StartRouteCommand;
use App\Application\Port\In\Batch\StartRouteResult;
use App\Application\Port\In\Batch\StartRouteUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Batch\StartRouteAction;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class StartRouteActionTest extends TestCase
{
    public function test_usa_el_jwt_y_el_path_aunque_el_cuerpo_intente_suplantar_al_conductor(): void
    {
        $useCase = $this->createMock(StartRouteUseCase::class);
        $useCase->expects($this->once())->method('execute')
            ->with($this->callback(static fn (StartRouteCommand $command): bool => $command->driver->id === 31 && $command->batchId === 210))
            ->willReturn(new StartRouteResult(210, 'L-ABC123', new DateTimeImmutable('2026-10-24T15:30:00Z'), 2));
        $response = (new StartRouteAction($useCase))(
            $this->request()->withParsedBody(['id_conductor' => 999, 'estado' => 'CERRADO', 'orden' => [9]]),
            (new ResponseFactory())->createResponse(), ['id' => '210'],
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('EN_RUTA', json_decode((string) $response->getBody(), true)['estado']);
    }

    public static function failures(): array
    {
        return [
            [new BatchNotFound('no existe'), 404, 'lote_no_encontrado'],
            [new BatchAccessDenied('ajeno'), 403, 'sin_permisos'],
            [new UserNotAllowed('inactivo'), 403, 'sin_permisos'],
            [new InvalidBatchTransition('en preparacion'), 400, 'transicion_invalida'],
            [new BatchHasNoPackages('vacio'), 409, 'lote_vacio'],
            [new RouteStartConflict('otra ruta', 'conductor_en_ruta'), 409, 'conductor_en_ruta'],
            [new RouteStartConflict('iniciado', 'lote_ya_iniciado'), 409, 'lote_ya_iniciado'],
            [new RouteStartConflict('paquetes', 'paquetes_no_asignados'), 409, 'paquetes_no_asignados'],
            [new RouteStartConflict('bloqueado'), 409, 'inicio_ruta_en_conflicto'],
        ];
    }

    #[DataProvider('failures')]
    public function test_traduce_los_errores_de_negocio(\RuntimeException $error, int $status, string $code): void
    {
        $useCase = $this->createStub(StartRouteUseCase::class);
        $useCase->method('execute')->willThrowException($error);
        $response = (new StartRouteAction($useCase))($this->request(), (new ResponseFactory())->createResponse(), ['id' => '210']);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, json_decode((string) $response->getBody(), true)['error']);
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['abc'], ['1.2'], [''], ['9999999999999999999']];
    }

    #[DataProvider('invalidIds')]
    public function test_rechaza_id_invalido_sin_ejecutar_el_caso_de_uso(string $id): void
    {
        $useCase = $this->createMock(StartRouteUseCase::class);
        $useCase->expects($this->never())->method('execute');
        $response = (new StartRouteAction($useCase))($this->request(), (new ResponseFactory())->createResponse(), ['id' => $id]);
        self::assertSame(422, $response->getStatusCode());
    }

    public function test_rechaza_una_peticion_sin_autenticacion(): void
    {
        $useCase = $this->createMock(StartRouteUseCase::class);
        $useCase->expects($this->never())->method('execute');
        $response = (new StartRouteAction($useCase))(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/batches/210/start-route'),
            (new ResponseFactory())->createResponse(), ['id' => '210'],
        );
        self::assertSame(401, $response->getStatusCode());
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/batches/210/start-route')
            ->withAttribute('authUser', new AuthenticatedUser(31, 'Juan', 'juan@example.invalid', 'CONDUCTOR'));
    }
}
