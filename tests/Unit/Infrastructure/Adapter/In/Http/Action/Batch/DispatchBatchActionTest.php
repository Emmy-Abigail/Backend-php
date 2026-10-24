<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Batch;

use App\Application\Exception\BatchAccessDenied;
use App\Application\Exception\BatchAlreadyDispatched;
use App\Application\Exception\BatchHasNoPackages;
use App\Application\Exception\BatchNotFound;
use App\Application\Exception\BatchVersionMismatch;
use App\Application\Exception\DispatchSnapshotChanged;
use App\Application\Exception\DriverNotAvailable;
use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\RouteUnavailable;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\DispatchBatchCommand;
use App\Application\Port\In\Batch\DispatchBatchUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Batch\DispatchBatchAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\BatchFixture;

require_once __DIR__ . '/../../../../../../../Support/BatchFixture.php';

final class DispatchBatchActionTest extends TestCase
{
    private function call(DispatchBatchUseCase $useCase, mixed $body, string $id = '210', ?AuthenticatedUser $user = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/batches/' . $id . '/dispatch');
        $request = $request->withAttribute('authUser', $user ?? BatchFixture::operator());
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return (new DispatchBatchAction($useCase))($request, (new ResponseFactory())->createResponse(), ['id' => $id]);
    }

    private function useCaseThatNeverRuns(): DispatchBatchUseCase
    {
        $useCase = $this->createMock(DispatchBatchUseCase::class);
        $useCase->expects($this->never())->method('execute');

        return $useCase;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function despacha_el_lote_y_responde_conductor_y_paradas(): void
    {
        $useCase = $this->createMock(DispatchBatchUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (DispatchBatchCommand $command): bool => $command->batchId === 210
                && $command->driverId === 31
                && $command->version === 0
                && $command->operator->id === 5))
            ->willReturn(BatchFixture::dispatchResult());

        $response = $this->call($useCase, ['id_conductor' => 31, 'version' => 0]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $data = $this->decode($response);
        self::assertSame('ASIGNADO', $data['estado']);
        self::assertSame(1, $data['version']);
        self::assertSame(31, $data['conductor']['id']);
        self::assertSame('AB5555', $data['conductor']['placa']);
        self::assertSame([1, 2], array_column($data['paradas'], 'orden'));
    }

    #[Test]
    public function ignora_campos_adicionales_del_cuerpo(): void
    {
        $useCase = $this->createMock(DispatchBatchUseCase::class);
        $useCase->expects($this->once())->method('execute')->willReturn(BatchFixture::dispatchResult());

        $response = $this->call($useCase, ['id_conductor' => 31, 'version' => 0, 'id_sede' => 5, 'orden' => [1, 2]]);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'falta id_conductor' => [['version' => 0], 'campo_obligatorio'],
            'falta version' => [['id_conductor' => 31], 'campo_obligatorio'],
            'id_conductor nulo' => [['id_conductor' => null, 'version' => 0], 'campo_obligatorio'],
            'version nula' => [['id_conductor' => 31, 'version' => null], 'campo_obligatorio'],
            'cuerpo vacio' => [[], 'campo_obligatorio'],
            'id_conductor como texto' => [['id_conductor' => '31', 'version' => 0], 'campo_invalido'],
            'id_conductor decimal' => [['id_conductor' => 31.0, 'version' => 0], 'campo_invalido'],
            'id_conductor booleano' => [['id_conductor' => true, 'version' => 0], 'campo_invalido'],
            'id_conductor cero' => [['id_conductor' => 0, 'version' => 0], 'campo_invalido'],
            'id_conductor negativo' => [['id_conductor' => -1, 'version' => 0], 'campo_invalido'],
            'version como texto' => [['id_conductor' => 31, 'version' => '0'], 'campo_invalido'],
            'version negativa' => [['id_conductor' => 31, 'version' => -1], 'campo_invalido'],
            'version booleana' => [['id_conductor' => 31, 'version' => false], 'campo_invalido'],
        ];
    }

    /** @param array<string, mixed> $body */
    #[Test]
    #[DataProvider('invalidBodies')]
    public function rechaza_cuerpos_invalidos_sin_despachar(array $body, string $expectedError): void
    {
        $response = $this->call($this->useCaseThatNeverRuns(), $body);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($expectedError, $this->decode($response)['error']);
    }

    #[Test]
    public function rechaza_un_cuerpo_que_es_una_lista(): void
    {
        $response = $this->call($this->useCaseThatNeverRuns(), [31, 0]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('cuerpo_invalido', $this->decode($response)['error']);
    }

    #[Test]
    public function rechaza_una_peticion_sin_cuerpo(): void
    {
        self::assertSame(400, $this->call($this->useCaseThatNeverRuns(), null)->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIds(): array
    {
        return ['cero' => ['0'], 'negativo' => ['-4'], 'letras' => ['abc'], 'decimal' => ['2.5']];
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function rechaza_un_id_de_lote_invalido(string $id): void
    {
        $response = $this->call($this->useCaseThatNeverRuns(), ['id_conductor' => 31, 'version' => 0], $id);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('id_invalido', $this->decode($response)['error']);
    }

    /**
     * @return array<string, array{RuntimeException, int, string}>
     */
    public static function failures(): array
    {
        return [
            'lote inexistente' => [new BatchNotFound('El lote no existe'), 404, 'lote_no_encontrado'],
            'lote de otra sede' => [new BatchAccessDenied('No puede despachar lotes de otra sede'), 403, 'sin_permisos'],
            'usuario sin permiso' => [new UserNotAllowed('no'), 403, 'sin_permisos'],
            'lote ya despachado' => [new BatchAlreadyDispatched('Este lote ya fue despachado por Ana Torres a Luis Ramos'), 409, 'lote_ya_despachado'],
            'version desactualizada' => [new BatchVersionMismatch('El lote cambió'), 409, 'version_desactualizada'],
            'el lote cambio durante el despacho' => [new DispatchSnapshotChanged('cambió'), 409, 'lote_cambio'],
            'lote sin paquetes' => [new BatchHasNoPackages('vacío'), 409, 'lote_vacio'],
            'conductor sin disponibilidad' => [new DriverNotAvailable('El conductor ya no está disponible'), 409, 'conductor_no_disponible'],
            'conductor inexistente o de otro vehiculo' => [new InvalidCatalogReference('El conductor indicado no existe'), 422, 'referencia_invalida'],
            'python no disponible' => [new RouteUnavailable('detalle interno'), 503, 'ruta_no_disponible'],
        ];
    }

    #[Test]
    #[DataProvider('failures')]
    public function traduce_cada_error_a_su_codigo_http(RuntimeException $exception, int $status, string $error): void
    {
        $useCase = $this->createStub(DispatchBatchUseCase::class);
        $useCase->method('execute')->willThrowException($exception);

        $response = $this->call($useCase, ['id_conductor' => 31, 'version' => 0]);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($error, $this->decode($response)['error']);
    }

    #[Test]
    public function el_mensaje_de_lote_ya_despachado_nombra_al_operador_y_al_conductor(): void
    {
        $useCase = $this->createStub(DispatchBatchUseCase::class);
        $useCase->method('execute')->willThrowException(new BatchAlreadyDispatched('Este lote ya fue despachado por Ana Torres a Luis Ramos'));

        $response = $this->call($useCase, ['id_conductor' => 31, 'version' => 0]);

        self::assertSame('Este lote ya fue despachado por Ana Torres a Luis Ramos', $this->decode($response)['message']);
    }

    #[Test]
    public function el_error_de_python_no_filtra_detalles_internos(): void
    {
        $useCase = $this->createStub(DispatchBatchUseCase::class);
        $useCase->method('execute')->willThrowException(new RouteUnavailable('http://interno:8000 clave-secreta'));

        $body = (string) $this->call($useCase, ['id_conductor' => 31, 'version' => 0])->getBody();

        self::assertStringNotContainsString('interno', $body);
        self::assertStringNotContainsString('secreta', $body);
        self::assertStringContainsString('Servicio de rutas no disponible', $body);
    }
}
