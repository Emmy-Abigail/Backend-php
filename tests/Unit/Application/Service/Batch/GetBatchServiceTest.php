<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Batch;

use App\Application\Exception\BatchAccessDenied;
use App\Application\Exception\BatchNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Batch\GetBatchQuery;
use App\Application\Port\Out\Batch\BatchReadRepository;
use App\Application\Service\Batch\GetBatchService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\BatchFixture;

require_once __DIR__ . '/../../../../Support/BatchFixture.php';

final class GetBatchServiceTest extends TestCase
{
    #[Test]
    public function el_operador_ve_paquetes_y_conductores_disponibles_de_un_lote_en_preparacion(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->method('findSummary')->willReturn(BatchFixture::summary('EN_PREPARACION', 1));
        $repository->expects($this->once())->method('packagesOf')->with(210)->willReturn([BatchFixture::package(1)]);
        // El tipo de vehículo del lote decide qué conductores se ofrecen.
        $repository->expects($this->once())->method('availableDrivers')->with(1)->willReturn([BatchFixture::candidate()]);

        $result = (new GetBatchService($repository))->execute(new GetBatchQuery(BatchFixture::operator(1), 210));

        self::assertSame('OPERADOR', $result->audience);
        self::assertCount(1, $result->packages);
        self::assertCount(1, $result->drivers);
    }

    #[Test]
    public function un_lote_ya_despachado_no_ofrece_conductores(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->method('findSummary')->willReturn(BatchFixture::summary('ASIGNADO', 1, BatchFixture::driverData()));
        $repository->method('packagesOf')->willReturn([BatchFixture::package(1, 1, 'ASIGNADO')]);
        $repository->expects($this->never())->method('availableDrivers');

        $result = (new GetBatchService($repository))->execute(new GetBatchQuery(BatchFixture::operator(1), 210));

        self::assertSame([], $result->drivers);
    }

    #[Test]
    public function el_operador_no_ve_lotes_de_otra_sede(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->method('findSummary')->willReturn(BatchFixture::summary('EN_PREPARACION', 2));
        $repository->expects($this->never())->method('packagesOf');
        $repository->expects($this->never())->method('availableDrivers');

        $this->expectException(BatchAccessDenied::class);

        (new GetBatchService($repository))->execute(new GetBatchQuery(BatchFixture::operator(1), 210));
    }

    #[Test]
    public function falla_si_el_lote_no_existe(): void
    {
        $repository = $this->createStub(BatchReadRepository::class);
        $repository->method('findSummary')->willReturn(null);

        $this->expectException(BatchNotFound::class);

        (new GetBatchService($repository))->execute(new GetBatchQuery(BatchFixture::operator(1), 999));
    }

    #[Test]
    public function el_conductor_ve_las_paradas_de_su_lote(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->method('findSummary')->willReturn(BatchFixture::summary('ASIGNADO', 1, BatchFixture::driverData(31)));
        $repository->method('packagesOf')->willReturn([BatchFixture::package(1, 1, 'ASIGNADO')]);
        $repository->expects($this->never())->method('availableDrivers');

        $result = (new GetBatchService($repository))->execute(new GetBatchQuery(BatchFixture::driver(31), 210));

        self::assertSame('CONDUCTOR', $result->audience);
        self::assertSame([], $result->drivers);
    }

    #[Test]
    public function el_conductor_no_ve_el_lote_de_otro_conductor(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->method('findSummary')->willReturn(BatchFixture::summary('ASIGNADO', 1, BatchFixture::driverData(99)));
        $repository->expects($this->never())->method('packagesOf');

        $this->expectException(BatchAccessDenied::class);

        (new GetBatchService($repository))->execute(new GetBatchQuery(BatchFixture::driver(31), 210));
    }

    #[Test]
    public function el_conductor_no_ve_un_lote_que_todavia_no_tiene_conductor(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->method('findSummary')->willReturn(BatchFixture::summary('EN_PREPARACION', 1, null));
        $repository->expects($this->never())->method('packagesOf');

        $this->expectException(BatchAccessDenied::class);

        (new GetBatchService($repository))->execute(new GetBatchQuery(BatchFixture::driver(31), 210));
    }

    #[Test]
    public function rechaza_a_un_administrador_sin_consultar_nada(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->expects($this->never())->method('findSummary');

        $this->expectException(UserNotAllowed::class);

        (new GetBatchService($repository))->execute(new GetBatchQuery(BatchFixture::admin(), 210));
    }

    #[Test]
    public function rechaza_a_quien_debe_cambiar_su_contrasena(): void
    {
        $repository = $this->createMock(BatchReadRepository::class);
        $repository->expects($this->never())->method('findSummary');

        $this->expectException(UserNotAllowed::class);

        (new GetBatchService($repository))->execute(new GetBatchQuery(BatchFixture::operator(1, true), 210));
    }
}
