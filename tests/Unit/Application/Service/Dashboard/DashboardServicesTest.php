<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Dashboard;

use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Dashboard\GetDashboardMetricsQuery;
use App\Application\Port\In\Dashboard\ListFailedDeliveriesQuery;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Dashboard\DashboardReadRepository;
use App\Application\Port\Out\Dashboard\DashboardScope;
use App\Application\Service\Dashboard\DashboardAccess;
use App\Application\Service\Dashboard\GetDashboardMetricsService;
use App\Application\Service\Dashboard\ListFailedDeliveriesService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\DashboardFixture;

require_once __DIR__ . '/../../../../Support/DashboardFixture.php';

final class DashboardServicesTest extends TestCase
{
    private function catalogs(): CatalogRepository
    {
        $catalogs = $this->createStub(CatalogRepository::class);
        $catalogs->method('getSedes')->willReturn([DashboardFixture::sede(1, 'Sede Norte'), DashboardFixture::sede(2, 'Sede Sur')]);
        $catalogs->method('getFailureReasons')->willReturn(DashboardFixture::failureReasons());
        $catalogs->method('getVehicleTypes')->willReturn(DashboardFixture::vehicleTypes());

        return $catalogs;
    }

    private function operator(?int $sedeId = 2): AuthenticatedUser
    {
        return new AuthenticatedUser(21, 'Lucía', 'lucia@example.invalid', 'OPERADOR', false, $sedeId, 'Sede Sur');
    }

    private function admin(): AuthenticatedUser
    {
        return new AuthenticatedUser(1, 'Admin', 'admin@example.invalid', 'ADMINISTRADOR');
    }

    public function test_el_operador_siempre_consulta_su_propia_sede_aunque_pida_otra(): void
    {
        $expectedScope = new DashboardScope('2026-10-01', '2026-10-31', 2);
        $repository = $this->createMock(DashboardReadRepository::class);
        $repository->expects($this->once())->method('registrationGroups')->with($this->equalTo($expectedScope))->willReturn([]);
        $repository->expects($this->once())->method('terminalEvents')->with($this->equalTo($expectedScope))->willReturn([]);
        $repository->expects($this->once())->method('dispatchedPackages')->with($this->equalTo($expectedScope))->willReturn([]);
        $repository->expects($this->never())->method('operators');
        $repository->expects($this->once())->method('failedDeliveries')->with($this->equalTo($expectedScope), 1, 3)->willReturn([]);

        $catalogs = $this->catalogs();
        $result = (new GetDashboardMetricsService($repository, $catalogs, new DashboardAccess($catalogs)))
            ->execute(new GetDashboardMetricsQuery($this->operator(), '2026-10-01', '2026-10-31', 1));

        $data = $result->toArray()['data'];
        self::assertSame(['id' => 2, 'nombre' => 'Sede Sur'], $data['sede']);
        self::assertArrayHasKey('desempeno_operador', $data);
        self::assertArrayNotHasKey('ranking_sedes', $data);
    }

    public function test_el_administrador_sin_sede_ve_todas_y_recibe_ranking(): void
    {
        $repository = $this->createMock(DashboardReadRepository::class);
        $repository->method('registrationGroups')->willReturn([]);
        $repository->method('terminalEvents')->willReturn([]);
        $repository->method('dispatchedPackages')->willReturn([]);
        $repository->method('failedDeliveries')->willReturn([]);
        $repository->expects($this->once())->method('operators')->with(null)->willReturn([]);

        $catalogs = $this->catalogs();
        $data = (new GetDashboardMetricsService($repository, $catalogs, new DashboardAccess($catalogs)))
            ->execute(new GetDashboardMetricsQuery($this->admin(), '2026-10-01', '2026-10-31'))
            ->toArray()['data'];

        self::assertNull($data['sede']);
        self::assertSame([1, 2], array_column($data['ranking_sedes'], 'id_sede'));
        self::assertSame([], $data['operadores']);
    }

    public function test_el_administrador_con_sede_filtra_y_no_recibe_ranking(): void
    {
        $repository = $this->createMock(DashboardReadRepository::class);
        $repository->expects($this->once())->method('registrationGroups')
            ->with($this->equalTo(new DashboardScope('2026-10-01', '2026-10-31', 1)))->willReturn([]);
        $repository->method('terminalEvents')->willReturn([]);
        $repository->method('dispatchedPackages')->willReturn([]);
        $repository->method('failedDeliveries')->willReturn([]);
        $repository->expects($this->once())->method('operators')->with(1)->willReturn([]);

        $catalogs = $this->catalogs();
        $data = (new GetDashboardMetricsService($repository, $catalogs, new DashboardAccess($catalogs)))
            ->execute(new GetDashboardMetricsQuery($this->admin(), '2026-10-01', '2026-10-31', 1))
            ->toArray()['data'];

        self::assertSame(['id' => 1, 'nombre' => 'Sede Norte'], $data['sede']);
        self::assertArrayNotHasKey('ranking_sedes', $data);
    }

    public function test_rechaza_una_sede_inexistente(): void
    {
        $repository = $this->createMock(DashboardReadRepository::class);
        $repository->expects($this->never())->method('registrationGroups');

        $catalogs = $this->catalogs();
        $this->expectException(InvalidCatalogReference::class);
        (new GetDashboardMetricsService($repository, $catalogs, new DashboardAccess($catalogs)))
            ->execute(new GetDashboardMetricsQuery($this->admin(), '2026-10-01', '2026-10-31', 99));
    }

    #[DataProvider('usuariosSinPermiso')]
    public function test_rechaza_roles_sin_acceso(AuthenticatedUser $user): void
    {
        $repository = $this->createMock(DashboardReadRepository::class);
        $repository->expects($this->never())->method('countFailedDeliveries');

        $catalogs = $this->catalogs();
        $this->expectException(UserNotAllowed::class);
        (new ListFailedDeliveriesService($repository, new DashboardAccess($catalogs)))
            ->execute(new ListFailedDeliveriesQuery($user, '2026-10-01', '2026-10-31'));
    }

    /** @return array<string, array{AuthenticatedUser}> */
    public static function usuariosSinPermiso(): array
    {
        return [
            'conductor' => [new AuthenticatedUser(31, 'Conductor', 'c@example.invalid', 'CONDUCTOR', false, null, null, 1)],
            'operador sin sede' => [new AuthenticatedUser(21, 'Operador', 'o@example.invalid', 'OPERADOR')],
        ];
    }

    public function test_lista_los_fallidos_paginados_de_la_sede_del_operador(): void
    {
        $scope = new DashboardScope('2026-10-01', '2026-10-31', 2);
        $repository = $this->createMock(DashboardReadRepository::class);
        $repository->expects($this->once())->method('countFailedDeliveries')->with($this->equalTo($scope))->willReturn(9);
        $repository->expects($this->once())->method('failedDeliveries')->with($this->equalTo($scope), 2, 8)
            ->willReturn([DashboardFixture::failedDelivery(38)]);

        $result = (new ListFailedDeliveriesService($repository, new DashboardAccess($this->catalogs())))
            ->execute(new ListFailedDeliveriesQuery($this->operator(), '2026-10-01', '2026-10-31', null, 2, 8))
            ->toArray();

        self::assertSame([
            'id' => 38,
            'tracking_id' => 'TRK38',
            'destino' => 'Barranco',
            'sede' => 'Sede Sur',
            'motivo' => 'Cliente ausente',
            'motivo_codigo' => 'CLIENTE_AUSENTE',
            'fecha_fallo' => '2026-10-20T14:30:00-05:00',
        ], $result['data'][0]);
        self::assertSame([
            'page' => 2,
            'per_page' => 8,
            'total' => 9,
            'total_pages' => 2,
            'sede_nombre' => 'Sede Sur',
            'desde' => '2026-10-01',
            'hasta' => '2026-10-31',
        ], $result['meta']);
    }

    public function test_sin_fallidos_no_consulta_la_pagina(): void
    {
        $repository = $this->createMock(DashboardReadRepository::class);
        $repository->method('countFailedDeliveries')->willReturn(0);
        $repository->expects($this->never())->method('failedDeliveries');

        $result = (new ListFailedDeliveriesService($repository, new DashboardAccess($this->catalogs())))
            ->execute(new ListFailedDeliveriesQuery($this->admin(), '2026-10-01', '2026-10-31'))
            ->toArray();

        self::assertSame([], $result['data']);
        self::assertSame(0, $result['meta']['total_pages']);
        self::assertNull($result['meta']['sede_nombre']);
    }
}
