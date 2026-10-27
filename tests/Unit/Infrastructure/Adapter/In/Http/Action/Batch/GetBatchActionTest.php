<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Batch;

use App\Application\Exception\BatchAccessDenied;
use App\Application\Exception\BatchNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\GetBatchQuery;
use App\Application\Port\In\Batch\GetBatchResult;
use App\Application\Port\In\Batch\GetBatchUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Batch\GetBatchAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\BatchFixture;

require_once __DIR__ . '/../../../../../../../Support/BatchFixture.php';

final class GetBatchActionTest extends TestCase
{
    private function call(GetBatchUseCase $useCase, string $id, ?AuthenticatedUser $user = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/batches/' . $id);
        if ($user !== null) {
            $request = $request->withAttribute('authUser', $user);
        }

        return (new GetBatchAction($useCase))($request, (new ResponseFactory())->createResponse(), ['id' => $id]);
    }

    private function useCaseThatNeverRuns(): GetBatchUseCase
    {
        $useCase = $this->createMock(GetBatchUseCase::class);
        $useCase->expects($this->never())->method('execute');

        return $useCase;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function responde_el_detalle_para_el_operador(): void
    {
        $result = new GetBatchResult(
            BatchFixture::summary(),
            [BatchFixture::package(1)],
            [BatchFixture::candidate()],
            'OPERADOR',
        );
        $useCase = $this->createMock(GetBatchUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (GetBatchQuery $query): bool => $query->batchId === 210 && $query->viewer->role === 'OPERADOR'))
            ->willReturn($result);

        $response = $this->call($useCase, '210', BatchFixture::operator());

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame(210, $data['id']);
        self::assertSame(0, $data['version']);
        self::assertCount(1, $data['paquetes']);
        self::assertCount(1, $data['conductores_disponibles']);
        self::assertArrayNotHasKey('paradas', $data);
    }

    #[Test]
    public function responde_las_paradas_ordenadas_para_el_conductor(): void
    {
        $result = new GetBatchResult(
            BatchFixture::summary('ASIGNADO', 1, BatchFixture::driverData()),
            [BatchFixture::package(5003, 1, 'ASIGNADO'), BatchFixture::package(4991, 2, 'ASIGNADO')],
            [],
            'CONDUCTOR',
        );
        $useCase = $this->createStub(GetBatchUseCase::class);
        $useCase->method('execute')->willReturn($result);

        $data = $this->decode($this->call($useCase, '210', BatchFixture::driver()));

        self::assertSame([1, 2], array_column($data['paradas'], 'orden'));
        self::assertArrayHasKey('destinatario', $data['paradas'][0]);
        self::assertArrayHasKey('coordenadas', $data['paradas'][0]);
        self::assertArrayNotHasKey('paquetes', $data);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIds(): array
    {
        return [
            'cero' => ['0'],
            'negativo' => ['-1'],
            'letras' => ['abc'],
            'decimal' => ['1.5'],
            'mezclado' => ['12x'],
            'vacio' => [''],
            'muy largo' => ['1234567890123456789012'],
        ];
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function rechaza_un_id_invalido_sin_consultar(string $id): void
    {
        $response = $this->call($this->useCaseThatNeverRuns(), $id, BatchFixture::operator());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('id_invalido', $this->decode($response)['error']);
    }

    #[Test]
    public function responde_404_si_el_lote_no_existe(): void
    {
        $useCase = $this->createStub(GetBatchUseCase::class);
        $useCase->method('execute')->willThrowException(new BatchNotFound('El lote no existe'));

        $response = $this->call($useCase, '999', BatchFixture::operator());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('lote_no_encontrado', $this->decode($response)['error']);
    }

    #[Test]
    public function responde_403_si_el_lote_es_de_otra_sede_o_de_otro_conductor(): void
    {
        $useCase = $this->createStub(GetBatchUseCase::class);
        $useCase->method('execute')->willThrowException(new BatchAccessDenied('No puede ver lotes de otra sede'));

        $response = $this->call($useCase, '210', BatchFixture::operator());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('No puede ver lotes de otra sede', $this->decode($response)['message']);
    }

    #[Test]
    public function responde_403_si_el_usuario_no_esta_permitido(): void
    {
        $useCase = $this->createStub(GetBatchUseCase::class);
        $useCase->method('execute')->willThrowException(new UserNotAllowed('no'));

        self::assertSame(403, $this->call($useCase, '210', BatchFixture::admin())->getStatusCode());
    }

    #[Test]
    public function responde_401_sin_usuario_autenticado(): void
    {
        self::assertSame(401, $this->call($this->useCaseThatNeverRuns(), '210')->getStatusCode());
    }
}
