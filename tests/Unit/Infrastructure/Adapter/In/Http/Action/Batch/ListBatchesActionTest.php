<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Batch;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\BatchPage;
use App\Application\Port\In\Batch\ListBatchesQuery;
use App\Application\Port\In\Batch\ListBatchesUseCase;
use App\Domain\Batch\BatchStatus;
use App\Infrastructure\Adapter\In\Http\Action\Batch\ListBatchesAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\BatchFixture;

require_once __DIR__ . '/../../../../../../../Support/BatchFixture.php';

final class ListBatchesActionTest extends TestCase
{
    /** @param array<string, mixed> $query */
    private function call(ListBatchesUseCase $useCase, array $query = [], ?AuthenticatedUser $user = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/batches')->withQueryParams($query);
        if ($user !== null) {
            $request = $request->withAttribute('authUser', $user);
        }

        return (new ListBatchesAction($useCase))($request, (new ResponseFactory())->createResponse());
    }

    private function useCaseThatNeverRuns(): ListBatchesUseCase
    {
        $useCase = $this->createMock(ListBatchesUseCase::class);
        $useCase->expects($this->never())->method('execute');

        return $useCase;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function responde_la_pagina_con_los_valores_por_defecto(): void
    {
        $useCase = $this->createMock(ListBatchesUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (ListBatchesQuery $query): bool => $query->status === null
                && $query->page === 1
                && $query->size === 20
                && $query->viewer->id === 5))
            ->willReturn(new BatchPage([BatchFixture::summary()], 1, 20, 1));

        $response = $this->call($useCase, [], BatchFixture::operator());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $data = $this->decode($response);
        self::assertCount(1, $data['data']);
        self::assertSame(['page' => 1, 'size' => 20, 'total' => 1, 'total_pages' => 1], $data['meta']);
        self::assertSame('L-ABC123', $data['data'][0]['codigo']);
    }

    #[Test]
    public function pasa_el_filtro_de_estado_y_la_paginacion(): void
    {
        $useCase = $this->createMock(ListBatchesUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (ListBatchesQuery $query): bool => $query->status === BatchStatus::EnPreparacion
                && $query->page === 2
                && $query->size === 50))
            ->willReturn(new BatchPage([], 2, 50, 0));

        $response = $this->call($useCase, ['estado' => 'EN_PREPARACION', 'page' => '2', 'size' => '50'], BatchFixture::operator());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function un_estado_vacio_se_trata_como_sin_filtro(): void
    {
        $useCase = $this->createMock(ListBatchesUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (ListBatchesQuery $query): bool => $query->status === null))
            ->willReturn(new BatchPage([], 1, 20, 0));

        self::assertSame(200, $this->call($useCase, ['estado' => ''], BatchFixture::operator())->getStatusCode());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidFilters(): array
    {
        return [
            'estado desconocido' => [['estado' => 'CANCELADO']],
            'estado en minusculas' => [['estado' => 'en_ruta']],
            'estado como arreglo' => [['estado' => ['EN_RUTA']]],
            'pagina cero' => [['page' => '0']],
            'pagina negativa' => [['page' => '-1']],
            'pagina con letras' => [['page' => 'abc']],
            'pagina decimal' => [['page' => '1.5']],
            'tamano cero' => [['size' => '0']],
            'tamano mayor al maximo' => [['size' => '101']],
            'tamano con letras' => [['size' => 'x']],
            'tamano como arreglo' => [['size' => ['10']]],
        ];
    }

    /** @param array<string, mixed> $query */
    #[Test]
    #[DataProvider('invalidFilters')]
    public function rechaza_filtros_invalidos_sin_consultar(array $query): void
    {
        $response = $this->call($this->useCaseThatNeverRuns(), $query, BatchFixture::operator());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('filtro_invalido', $this->decode($response)['error']);
    }

    #[Test]
    public function acepta_el_tamano_maximo(): void
    {
        $useCase = $this->createStub(ListBatchesUseCase::class);
        $useCase->method('execute')->willReturn(new BatchPage([], 1, 100, 0));

        self::assertSame(200, $this->call($useCase, ['size' => '100'], BatchFixture::operator())->getStatusCode());
    }

    #[Test]
    public function responde_403_si_el_caso_de_uso_rechaza_al_usuario(): void
    {
        $useCase = $this->createStub(ListBatchesUseCase::class);
        $useCase->method('execute')->willThrowException(new UserNotAllowed('no'));

        $response = $this->call($useCase, [], BatchFixture::admin());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('sin_permisos', $this->decode($response)['error']);
    }

    #[Test]
    public function responde_401_sin_usuario_autenticado(): void
    {
        $response = $this->call($this->useCaseThatNeverRuns());

        self::assertSame(401, $response->getStatusCode());
    }
}
