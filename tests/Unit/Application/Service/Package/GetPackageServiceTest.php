<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Package;

use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\GetPackageQuery;
use App\Application\Port\Out\Persistence\PackageDetail;
use App\Application\Port\Out\Persistence\PackageQueryRepository;
use App\Application\Service\Package\GetPackageService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GetPackageServiceTest extends TestCase
{
    private const HISTORY = [['estado_anterior' => null, 'estado_nuevo' => 'EN_ALMACEN']];

    private function detail(int $sedeId = 1, ?int $driverId = 7, ?string $batchStatus = 'ASIGNADO'): PackageDetail
    {
        return new PackageDetail($sedeId, $driverId, $batchStatus, [
            'id' => 10,
            'tracking_id' => 'AB23456789',
            'destinatario' => ['nombres' => 'Maria', 'dni' => '41234567', 'celular' => '912345678'],
            'costo_estimado' => '15.50',
            'remitente' => ['nombres' => 'Remitente', 'celular' => '987654321'],
        ], self::HISTORY);
    }

    private function service(?PackageDetail $detail): GetPackageService
    {
        $repository = $this->createStub(PackageQueryRepository::class);
        $repository->method('findDetail')->willReturn($detail);

        return new GetPackageService($repository);
    }

    private function operator(?int $sedeId = 1): AuthenticatedUser
    {
        return new AuthenticatedUser(2, 'Ana', 'ana@example.invalid', 'OPERADOR', false, $sedeId);
    }

    private function driver(int $id = 7): AuthenticatedUser
    {
        return new AuthenticatedUser($id, 'Juan', 'juan@example.invalid', 'CONDUCTOR');
    }

    public function test_el_operador_de_la_sede_ve_todo_el_detalle_con_historial(): void
    {
        $result = $this->service($this->detail())->execute(new GetPackageQuery($this->operator(), 10))->toArray();

        self::assertSame('AB23456789', $result['tracking_id']);
        self::assertSame('15.50', $result['costo_estimado']);
        self::assertSame('Remitente', $result['remitente']['nombres']);
        self::assertSame(self::HISTORY, $result['historial']);
    }

    public function test_el_operador_de_otra_sede_no_puede_verlo(): void
    {
        $this->expectException(PackageAccessDenied::class);
        $this->service($this->detail(sedeId: 3))->execute(new GetPackageQuery($this->operator(1), 10));
    }

    #[DataProvider('lotesDelConductor')]
    public function test_el_conductor_asignado_ve_el_paquete_sin_remitente_ni_costo(string $batchStatus): void
    {
        $result = $this->service($this->detail(batchStatus: $batchStatus))->execute(new GetPackageQuery($this->driver(7), 10))->toArray();

        self::assertSame('912345678', $result['destinatario']['celular']);
        self::assertArrayNotHasKey('remitente', $result);
        self::assertArrayNotHasKey('costo_estimado', $result);
        self::assertSame(self::HISTORY, $result['historial']);
    }

    public static function lotesDelConductor(): array
    {
        return [['ASIGNADO'], ['EN_RUTA']];
    }

    #[DataProvider('paquetesAjenosAlConductor')]
    public function test_el_conductor_no_ve_paquetes_que_no_estan_en_su_lote_activo(?int $driverId, ?string $batchStatus): void
    {
        $this->expectException(PackageAccessDenied::class);
        $this->service($this->detail(driverId: $driverId, batchStatus: $batchStatus))->execute(new GetPackageQuery($this->driver(7), 10));
    }

    public static function paquetesAjenosAlConductor(): array
    {
        return [
            'lote de otro conductor' => [8, 'ASIGNADO'],
            'lote sin conductor' => [null, 'EN_PREPARACION'],
            'paquete sin lote' => [null, null],
            'lote ya cerrado' => [7, 'CERRADO'],
        ];
    }

    public function test_paquete_inexistente(): void
    {
        $this->expectException(PackageNotFound::class);
        $this->service(null)->execute(new GetPackageQuery($this->operator(), 999));
    }

    #[DataProvider('usuariosSinPermiso')]
    public function test_rechaza_a_quien_no_es_operador_con_sede_ni_conductor(AuthenticatedUser $user): void
    {
        $repository = $this->createMock(PackageQueryRepository::class);
        $repository->expects($this->never())->method('findDetail');

        $this->expectException(UserNotAllowed::class);
        (new GetPackageService($repository))->execute(new GetPackageQuery($user, 10));
    }

    public static function usuariosSinPermiso(): array
    {
        return [
            'administrador' => [new AuthenticatedUser(1, 'Admin', 'admin@example.invalid', 'ADMINISTRADOR')],
            'operador sin sede' => [new AuthenticatedUser(2, 'Ana', 'ana@example.invalid', 'OPERADOR', false, null)],
        ];
    }
}
