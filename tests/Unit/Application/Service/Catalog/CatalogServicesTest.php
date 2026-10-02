<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Catalog;

use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Service\Catalog\GetFailureReasonsService;
use App\Application\Service\Catalog\GetGeographyService;
use App\Application\Service\Catalog\GetSedesService;
use App\Application\Service\Catalog\GetVehicleTypesService;
use App\Domain\Catalog\Department;
use App\Domain\Catalog\District;
use App\Domain\Catalog\FailureReason;
use App\Domain\Catalog\Province;
use App\Domain\Catalog\Sede;
use App\Domain\Catalog\VehicleType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogServicesTest extends TestCase
{
    #[Test]
    public function get_geography_service_retorna_departamentos(): void
    {
        $repo = $this->createMock(CatalogRepository::class);
        $repo->expects($this->once())
            ->method('getGeography')
            ->willReturn([
                new Department('15', 'Lima', [
                    new Province('1501', '15', 'Lima', [
                        new District('150117', '1501', 1, 'Los Olivos', 'Los Olivos', -11.99, -77.07, true),
                    ]),
                ]),
            ]);

        $service = new GetGeographyService($repo);
        $result = $service->execute();

        self::assertCount(1, $result);
        self::assertSame('15', $result[0]->ubigeo);
        self::assertSame('Lima', $result[0]->name);
        self::assertCount(1, $result[0]->provinces);
        self::assertSame('150117', $result[0]->provinces[0]->districts[0]->ubigeo);
    }

    #[Test]
    public function get_sedes_service_retorna_sedes(): void
    {
        $repo = $this->createMock(CatalogRepository::class);
        $repo->expects($this->once())
            ->method('getSedes')
            ->willReturn([
                new Sede(1, 'Sede Norte', 1, 'NORTE', 'Norte', '150117', 'Los Olivos', 'Av. Carlos Izaguirre', -11.99, -77.07, true),
            ]);

        $service = new GetSedesService($repo);
        $result = $service->execute();

        self::assertCount(1, $result);
        self::assertSame('Sede Norte', $result[0]->nombre);
        self::assertSame('NORTE', $result[0]->zonaCodigo);
    }

    #[Test]
    public function get_vehicle_types_service_retorna_tipos(): void
    {
        $repo = $this->createMock(CatalogRepository::class);
        $repo->expects($this->once())
            ->method('getVehicleTypes')
            ->willReturn([
                new VehicleType(1, 'MOTORIZADO', 'Motorizado', 1, 20.0, 40.0, 20),
            ]);

        $service = new GetVehicleTypesService($repo);
        $result = $service->execute();

        self::assertCount(1, $result);
        self::assertSame('MOTORIZADO', $result[0]->codigo);
        self::assertSame(20.0, $result[0]->pesoMaxTotalKg);
    }

    #[Test]
    public function get_failure_reasons_service_retorna_motivos(): void
    {
        $repo = $this->createMock(CatalogRepository::class);
        $repo->expects($this->once())
            ->method('getFailureReasons')
            ->willReturn([
                new FailureReason(1, 'CLIENTE_AUSENTE', 'Cliente ausente'),
            ]);

        $service = new GetFailureReasonsService($repo);
        $result = $service->execute();

        self::assertCount(1, $result);
        self::assertSame('CLIENTE_AUSENTE', $result[0]->codigo);
    }
}