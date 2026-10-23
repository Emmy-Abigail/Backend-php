<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Batch;

use App\Application\Exception\BatchAlreadyDispatched;
use App\Application\Exception\BatchVersionMismatch;
use App\Application\Exception\DispatchSnapshotChanged;
use App\Application\Exception\DriverNotAvailable;
use App\Application\Exception\RouteUnavailable;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Batch\DispatchBatchCommand;
use App\Application\Port\Out\Batch\BatchDispatchRepository;
use App\Application\Port\Out\Batch\DispatchSnapshot;
use App\Application\Port\Out\Route\RouteOptimizerGateway;
use App\Application\Port\Out\Route\RouteStop;
use App\Application\Service\Batch\DispatchBatchService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\BatchFixture;

require_once __DIR__ . '/../../../../Support/BatchFixture.php';

final class DispatchBatchServiceTest extends TestCase
{
    private function snapshot(int $version = 0): DispatchSnapshot
    {
        return new DispatchSnapshot(210, $version, 1, 1, [
            new RouteStop(4990, '150140'),
            new RouteStop(4991, '150104'),
            new RouteStop(5003, '150122'),
        ]);
    }

    private function command(int $version = 0, int $sedeId = 1): DispatchBatchCommand
    {
        return new DispatchBatchCommand(BatchFixture::operator($sedeId), 210, 31, $version);
    }

    #[Test]
    public function pide_la_ruta_a_python_con_las_paradas_del_lote_y_guarda_el_despacho(): void
    {
        $snapshot = $this->snapshot();
        $command = $this->command();

        $repository = $this->createMock(BatchDispatchRepository::class);
        $repository->expects($this->once())->method('snapshot')->with(210, 1)->willReturn($snapshot);
        $repository->expects($this->once())
            ->method('dispatch')
            ->with($command, $snapshot, [5003, 4991, 4990])
            ->willReturn(BatchFixture::dispatchResult());

        $routes = $this->createMock(RouteOptimizerGateway::class);
        $routes->expects($this->once())->method('order')->with(1, $snapshot->stops)->willReturn([5003, 4991, 4990]);

        $result = (new DispatchBatchService($repository, $routes))->execute($command);

        self::assertSame(210, $result->batchId);
        self::assertSame(1, $result->version);
    }

    #[Test]
    public function rechaza_una_version_desactualizada_sin_llamar_a_python(): void
    {
        $repository = $this->createMock(BatchDispatchRepository::class);
        $repository->method('snapshot')->willReturn($this->snapshot(1));
        $repository->expects($this->never())->method('dispatch');

        $routes = $this->createMock(RouteOptimizerGateway::class);
        $routes->expects($this->never())->method('order');

        $this->expectException(BatchVersionMismatch::class);

        (new DispatchBatchService($repository, $routes))->execute($this->command(0));
    }

    #[Test]
    public function si_el_lote_cambia_mientras_se_calcula_la_ruta_vuelve_a_leerlo_y_a_pedir_el_orden(): void
    {
        $calls = 0;
        $repository = $this->createMock(BatchDispatchRepository::class);
        $repository->expects($this->exactly(2))->method('snapshot')->willReturn($this->snapshot());
        $repository->expects($this->exactly(2))->method('dispatch')->willReturnCallback(
            static function () use (&$calls) {
                if (++$calls === 1) {
                    throw new DispatchSnapshotChanged('entro un paquete');
                }

                return BatchFixture::dispatchResult();
            },
        );

        $routes = $this->createMock(RouteOptimizerGateway::class);
        $routes->expects($this->exactly(2))->method('order')->willReturn([4990, 4991, 5003]);

        $result = (new DispatchBatchService($repository, $routes))->execute($this->command());

        self::assertSame(210, $result->batchId);
    }

    #[Test]
    public function se_rinde_tras_tres_intentos(): void
    {
        $repository = $this->createMock(BatchDispatchRepository::class);
        $repository->expects($this->exactly(3))->method('snapshot')->willReturn($this->snapshot());
        $repository->expects($this->exactly(3))->method('dispatch')->willThrowException(new DispatchSnapshotChanged('cambia siempre'));

        $routes = $this->createStub(RouteOptimizerGateway::class);
        $routes->method('order')->willReturn([4990, 4991, 5003]);

        $this->expectException(DispatchSnapshotChanged::class);

        (new DispatchBatchService($repository, $routes))->execute($this->command());
    }

    #[Test]
    public function si_python_no_responde_no_se_guarda_nada(): void
    {
        $repository = $this->createMock(BatchDispatchRepository::class);
        $repository->method('snapshot')->willReturn($this->snapshot());
        $repository->expects($this->never())->method('dispatch');

        $routes = $this->createStub(RouteOptimizerGateway::class);
        $routes->method('order')->willThrowException(new RouteUnavailable('Servicio de rutas no disponible'));

        $this->expectException(RouteUnavailable::class);

        (new DispatchBatchService($repository, $routes))->execute($this->command());
    }

    #[Test]
    public function los_errores_de_negocio_no_se_reintentan(): void
    {
        $repository = $this->createMock(BatchDispatchRepository::class);
        $repository->expects($this->once())->method('snapshot')->willReturn($this->snapshot());
        $repository->expects($this->once())->method('dispatch')->willThrowException(new DriverNotAvailable('El conductor ya no está disponible'));

        $routes = $this->createStub(RouteOptimizerGateway::class);
        $routes->method('order')->willReturn([4990, 4991, 5003]);

        $this->expectException(DriverNotAvailable::class);

        (new DispatchBatchService($repository, $routes))->execute($this->command());
    }

    #[Test]
    public function un_lote_ya_despachado_se_informa_desde_la_lectura(): void
    {
        $repository = $this->createMock(BatchDispatchRepository::class);
        $repository->method('snapshot')->willThrowException(new BatchAlreadyDispatched('Este lote ya fue despachado por Ana Torres a Luis Ramos'));
        $repository->expects($this->never())->method('dispatch');

        $routes = $this->createMock(RouteOptimizerGateway::class);
        $routes->expects($this->never())->method('order');

        $this->expectException(BatchAlreadyDispatched::class);
        $this->expectExceptionMessage('Ana Torres');

        (new DispatchBatchService($repository, $routes))->execute($this->command());
    }

    #[Test]
    public function usa_la_sede_del_operador_autenticado(): void
    {
        $repository = $this->createMock(BatchDispatchRepository::class);
        $repository->expects($this->once())->method('snapshot')->with(210, 4)->willReturn($this->snapshot());
        $repository->method('dispatch')->willReturn(BatchFixture::dispatchResult());

        $routes = $this->createStub(RouteOptimizerGateway::class);
        $routes->method('order')->willReturn([4990, 4991, 5003]);

        (new DispatchBatchService($repository, $routes))->execute($this->command(0, 4));
    }

    #[Test]
    public function solo_un_operador_habilitado_con_sede_puede_despachar(): void
    {
        $repository = $this->createMock(BatchDispatchRepository::class);
        $repository->expects($this->never())->method('snapshot');
        $routes = $this->createMock(RouteOptimizerGateway::class);
        $routes->expects($this->never())->method('order');
        $service = new DispatchBatchService($repository, $routes);

        foreach ([BatchFixture::admin(), BatchFixture::driver(), BatchFixture::operatorWithoutSede(), BatchFixture::operator(1, true)] as $user) {
            try {
                $service->execute(new DispatchBatchCommand($user, 210, 31, 0));
                self::fail('Se acepto a un usuario sin permiso para despachar');
            } catch (UserNotAllowed) {
                self::addToAssertionCount(1);
            }
        }
    }
}
