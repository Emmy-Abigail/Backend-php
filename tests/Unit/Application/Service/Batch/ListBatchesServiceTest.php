<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Batch;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Batch\BatchPage;
use App\Application\Port\In\Batch\ListBatchesQuery;
use App\Application\Port\Out\Batch\BatchReadRepository;
use App\Application\Service\Batch\ListBatchesService;
use App\Domain\Batch\BatchStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\BatchFixture;

require_once __DIR__ . '/../../../../Support/BatchFixture.php';

final class ListBatchesServiceTest extends TestCase
{
    private function page(): BatchPage
    {
        return new BatchPage([BatchFixture::summary()], 1, 20, 1);
    }

    #[Test]
    public function el_operador_ve_los_lotes_de_su_sede_en_todos_los_estados(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->expects($this->once())
            ->method('search')
            ->with(1, null, BatchStatus::cases(), 1, 20)
            ->willReturn($this->page());

        $result = (new ListBatchesService($repository))->execute(new ListBatchesQuery(BatchFixture::operator(1), null, 1, 20));

        self::assertSame($this->page()->total, $result->total);
    }

    #[Test]
    public function el_operador_puede_filtrar_por_estado(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->expects($this->once())
            ->method('search')
            ->with(4, null, [BatchStatus::EnPreparacion], 2, 50)
            ->willReturn($this->page());

        (new ListBatchesService($repository))->execute(
            new ListBatchesQuery(BatchFixture::operator(4), BatchStatus::EnPreparacion, 2, 50),
        );
    }

    #[Test]
    public function el_conductor_ve_por_defecto_sus_lotes_asignados_y_en_ruta(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->expects($this->once())
            ->method('search')
            ->with(null, 31, [BatchStatus::Asignado, BatchStatus::EnRuta], 1, 20)
            ->willReturn($this->page());

        (new ListBatchesService($repository))->execute(new ListBatchesQuery(BatchFixture::driver(31), null, 1, 20));
    }

    #[Test]
    public function el_conductor_puede_pedir_un_estado_concreto_pero_solo_de_sus_lotes(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->expects($this->once())
            ->method('search')
            ->with(null, 31, [BatchStatus::Cerrado], 1, 20)
            ->willReturn($this->page());

        (new ListBatchesService($repository))->execute(new ListBatchesQuery(BatchFixture::driver(31), BatchStatus::Cerrado, 1, 20));
    }

    #[Test]
    public function rechaza_a_un_administrador(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->expects($this->never())->method('search');

        $this->expectException(UserNotAllowed::class);

        (new ListBatchesService($repository))->execute(new ListBatchesQuery(BatchFixture::admin(), null, 1, 20));
    }

    #[Test]
    public function rechaza_a_un_operador_sin_sede(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->expects($this->never())->method('search');

        $this->expectException(UserNotAllowed::class);

        (new ListBatchesService($repository))->execute(new ListBatchesQuery(BatchFixture::operatorWithoutSede(), null, 1, 20));
    }

    #[Test]
    public function rechaza_a_quien_debe_cambiar_su_contrasena(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->expects($this->never())->method('search');

        $this->expectException(UserNotAllowed::class);

        (new ListBatchesService($repository))->execute(new ListBatchesQuery(BatchFixture::operator(1, true), null, 1, 20));
    }
}
