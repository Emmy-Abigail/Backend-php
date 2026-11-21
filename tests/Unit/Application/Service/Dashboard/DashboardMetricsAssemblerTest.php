<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Dashboard;

use App\Application\Port\Out\Dashboard\DispatchedPackage;
use App\Application\Port\Out\Dashboard\OperatorReference;
use App\Application\Port\Out\Dashboard\RegistrationGroup;
use App\Application\Port\Out\Dashboard\TerminalEvent;
use App\Application\Service\Dashboard\DashboardMetricsAssembler;
use PHPUnit\Framework\TestCase;
use Tests\Support\DashboardFixture;

require_once __DIR__ . '/../../../../Support/DashboardFixture.php';

final class DashboardMetricsAssemblerTest extends TestCase
{
    /** @return array<string, mixed> */
    private function assemble(string $role, int $userId, ?array $sede): array
    {
        $events = [];
        for ($i = 1; $i <= 30; $i++) {
            $events[] = DashboardFixture::delivered($i, 1, 10);
        }
        for ($i = 31; $i <= 35; $i++) {
            $events[] = DashboardFixture::delivered($i, 2, 20, 12, 'Ana Auto', 'AUTO');
        }
        $events[] = DashboardFixture::failed(36, 1, 'DIRECCION_ERRONEA', true, 'ENTREGADO');
        $events[] = DashboardFixture::failed(37, 1, 'DIRECCION_ERRONEA');
        $events[] = DashboardFixture::failed(38, 1, 'CLIENTE_AUSENTE');

        return (new DashboardMetricsAssembler())->assemble(
            role: $role,
            userId: $userId,
            sede: $sede,
            registrations: [
                new RegistrationGroup(1, 21, 'ENTREGADO', null, 30),
                new RegistrationGroup(1, 21, 'FALLIDO', 'DIRECCION_ERRONEA', 2),
                new RegistrationGroup(1, 21, 'EN_ALMACEN', null, 3),
                new RegistrationGroup(2, 22, 'ENTREGADO', null, 5),
            ],
            events: $events,
            dispatched: [
                new DispatchedPackage(21, DashboardFixture::utc('2026-10-10 08:00:00'), DashboardFixture::utc('2026-10-10 12:00:00')),
                new DispatchedPackage(21, DashboardFixture::utc('2026-10-10 06:00:00'), DashboardFixture::utc('2026-10-10 12:00:00')),
            ],
            operators: [
                new OperatorReference(21, 'Lucía Vega', 'Sede Norte', true),
                new OperatorReference(22, 'Ana Torres', 'Sede Sur', true),
                new OperatorReference(23, 'Operador Inactivo', 'Sede Sur', false),
            ],
            latestFailures: [DashboardFixture::failedDelivery(38)],
            sedes: [
                DashboardFixture::sede(1, 'Sede Norte'),
                DashboardFixture::sede(2, 'Sede Sur'),
                DashboardFixture::sede(3, 'Sede Cerrada', false),
            ],
            failureReasons: DashboardFixture::failureReasons(),
            vehicleTypes: DashboardFixture::vehicleTypes(),
        );
    }

    public function test_calcula_los_kpis_y_el_flujo_de_paquetes(): void
    {
        $metrics = $this->assemble('ADMINISTRADOR', 1, null);

        self::assertSame([
            'paquetes_registrados' => 40,
            'entregas_exitosas_pct' => 92.1,
            'entregas_exitosas_qty' => 35,
            'finalizados_qty' => 38,
            'tiempo_promedio_horas' => 11.4,
        ], $metrics['kpis']);
        self::assertSame(40, $metrics['flujo_paquetes']['total']);
        self::assertSame(['cantidad' => 3, 'porcentaje' => 7.5], $metrics['flujo_paquetes']['en_almacen']);
        self::assertSame(['cantidad' => 0, 'porcentaje' => 0.0], $metrics['flujo_paquetes']['asignado']);
        self::assertSame(['cantidad' => 35, 'porcentaje' => 87.5], $metrics['flujo_paquetes']['entregado']);
        self::assertSame(['cantidad' => 2, 'porcentaje' => 5.0], $metrics['flujo_paquetes']['fallido']);
        self::assertSame(['total', 'en_almacen', 'asignado', 'en_ruta', 'entregado', 'fallido'], array_keys($metrics['flujo_paquetes']));
    }

    public function test_el_ranking_marca_la_sede_mas_baja_y_las_que_no_tienen_datos_suficientes(): void
    {
        $ranking = $this->assemble('ADMINISTRADOR', 1, null)['ranking_sedes'];

        self::assertSame([1, 2], array_column($ranking, 'id_sede'), 'La sede inactiva sin movimiento no aparece');
        self::assertSame(35, $ranking[0]['paquetes_registrados']);
        self::assertSame(30, $ranking[0]['entregas_exitosas_qty'], 'Sede 1: 30 entregadas de 33 terminadas');
        self::assertSame(90.9, $ranking[0]['entregas_exitosas_pct']);
        self::assertSame(33, $ranking[0]['finalizados_qty']);
        self::assertSame(5, $ranking[1]['entregas_exitosas_qty'], 'Sede 2: 5 entregadas, menos de 10 terminadas');
        self::assertSame(3, $ranking[0]['fallidos_qty']);
        self::assertSame(10.0, $ranking[0]['tiempo_promedio_horas']);
        self::assertTrue($ranking[0]['es_mas_baja']);
        self::assertFalse($ranking[0]['datos_insuficientes']);
        self::assertFalse($ranking[1]['es_mas_baja']);
        self::assertTrue($ranking[1]['datos_insuficientes']);
    }

    public function test_agrupa_los_fallidos_por_motivo_y_cuenta_las_direcciones_corregidas(): void
    {
        $failures = $this->assemble('ADMINISTRADOR', 1, null)['fallidos_motivo'];

        self::assertSame(3, $failures['total_fallidos']);
        self::assertSame(['DIRECCION_ERRONEA', 'CLIENTE_AUSENTE', 'RECHAZADO', 'OTRO'], array_column($failures['motivos'], 'codigo'));
        self::assertSame(['codigo' => 'DIRECCION_ERRONEA', 'motivo' => 'Dirección errónea', 'cantidad' => 2, 'porcentaje' => 66.7], $failures['motivos'][0]);
        self::assertSame(['total_fallidos_direccion' => 2, 'reintentados' => 1, 'entregados' => 1], $failures['direcciones_corregidas']);
        self::assertSame('TRK38', $failures['ultimos_fallidos'][0]['tracking_id']);
        self::assertSame('2026-10-20T14:30:00-05:00', $failures['ultimos_fallidos'][0]['fecha']);
    }

    public function test_arma_el_top_de_conductores_y_las_entregas_por_vehiculo(): void
    {
        $metrics = $this->assemble('ADMINISTRADOR', 1, null);
        $top = $metrics['top_conductores'];

        self::assertSame(['todos', 'motorizado', 'auto', 'camion'], array_keys($top));
        self::assertSame([11, 12], array_column($top['todos'], 'id_conductor'));
        self::assertSame([
            'id_conductor' => 11,
            'nombre' => 'Luis Ramos',
            'tipo_vehiculo' => 'MOTORIZADO',
            'tipo_vehiculo_label' => 'Motorizado',
            'placa' => 'ABC-11',
            'entregas_qty' => 30,
        ], $top['todos'][0]);
        self::assertSame([12], array_column($top['auto'], 'id_conductor'));
        self::assertSame([], $top['camion']);

        self::assertSame([
            'total' => 35,
            'motorizado' => ['cantidad' => 30, 'porcentaje' => 85.7],
            'auto' => ['cantidad' => 5, 'porcentaje' => 14.3],
            'camion' => ['cantidad' => 0, 'porcentaje' => 0.0],
        ], $metrics['entregas_vehiculo']);
    }

    public function test_el_administrador_recibe_la_tabla_de_operadores(): void
    {
        $metrics = $this->assemble('ADMINISTRADOR', 1, null);

        self::assertArrayNotHasKey('desempeno_operador', $metrics);
        self::assertSame([21, 22], array_column($metrics['operadores'], 'id_usuario'), 'El inactivo sin trabajo no aparece');
        self::assertSame([
            'id_usuario' => 21,
            'nombre' => 'Lucía Vega',
            'sede' => 'Sede Norte',
            'registrados' => 35,
            'bien_registrados_pct' => 94.3,
            'errores_direccion_qty' => 2,
            'entregas_exitosas_pct' => 93.8,
            'tiempo_asignacion_horas' => 5.0,
        ], $metrics['operadores'][0]);
        self::assertNull($metrics['operadores'][1]['tiempo_asignacion_horas']);
        self::assertNull($metrics['sede']);
    }

    public function test_el_operador_ve_su_desempeno_sin_ranking_ni_tabla_de_operadores(): void
    {
        $metrics = $this->assemble('OPERADOR', 21, ['id' => 1, 'nombre' => 'Sede Norte']);

        self::assertArrayNotHasKey('ranking_sedes', $metrics);
        self::assertArrayNotHasKey('operadores', $metrics);
        self::assertSame(35, $metrics['desempeno_operador']['registrados']);
        self::assertSame(5.0, $metrics['desempeno_operador']['tiempo_asignacion_horas']);
        self::assertSame(['id' => 1, 'nombre' => 'Sede Norte'], $metrics['sede']);
    }

    public function test_una_entrega_sin_conductor_ni_lote_cuenta_en_kpis_pero_no_en_el_top(): void
    {
        $registered = DashboardFixture::utc('2026-10-10 12:00:00');
        $orphan = new TerminalEvent(99, 1, 'ENTREGADO', $registered->modify('+4 hours'), $registered);

        $metrics = (new DashboardMetricsAssembler())->assemble(
            'ADMINISTRADOR', 1, null, [], [$orphan, DashboardFixture::delivered(1, 1, 2)], [], [], [],
            [DashboardFixture::sede(1, 'Sede Norte')], DashboardFixture::failureReasons(), DashboardFixture::vehicleTypes(),
        );

        self::assertSame(2, $metrics['kpis']['entregas_exitosas_qty']);
        self::assertSame(3.0, $metrics['kpis']['tiempo_promedio_horas']);
        self::assertSame([11], array_column($metrics['top_conductores']['todos'], 'id_conductor'));
        // Sin lote no hay tipo de vehículo: no entra en la dona.
        self::assertSame(1, $metrics['entregas_vehiculo']['total']);
    }

    public function test_sin_datos_los_porcentajes_y_tiempos_son_null(): void
    {
        $metrics = (new DashboardMetricsAssembler())->assemble(
            'ADMINISTRADOR', 1, null, [], [], [], [], [], [DashboardFixture::sede(1, 'Sede Norte')],
            DashboardFixture::failureReasons(), DashboardFixture::vehicleTypes(),
        );

        self::assertSame(0, $metrics['kpis']['paquetes_registrados']);
        self::assertNull($metrics['kpis']['entregas_exitosas_pct']);
        self::assertNull($metrics['kpis']['tiempo_promedio_horas']);
        self::assertSame(0.0, $metrics['flujo_paquetes']['en_almacen']['porcentaje']);
        self::assertTrue($metrics['ranking_sedes'][0]['datos_insuficientes']);
        self::assertFalse($metrics['ranking_sedes'][0]['es_mas_baja']);
        self::assertSame([], $metrics['top_conductores']['todos']);
        self::assertSame([], $metrics['operadores']);
    }
}
