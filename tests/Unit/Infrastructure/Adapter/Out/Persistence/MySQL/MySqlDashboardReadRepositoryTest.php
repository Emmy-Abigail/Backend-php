<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\Out\Dashboard\DashboardScope;
use App\Application\Port\Out\Dashboard\RegistrationGroup;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlDashboardReadRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/** Ejecuta las consultas reales en SQLite en memoria; no accede a la BD del proyecto. */
final class MySqlDashboardReadRepositoryTest extends TestCase
{
    private const OCTOBER = ['2026-10-01', '2026-10-31'];

    private Connection $db;
    private MySqlDashboardReadRepository $repository;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        foreach ([
            'CREATE TABLE estados_paquete (id INTEGER PRIMARY KEY, codigo TEXT, nombre TEXT)',
            'CREATE TABLE motivos_fallo (id INTEGER PRIMARY KEY, codigo TEXT, nombre TEXT)',
            'CREATE TABLE tipos_vehiculo (id INTEGER PRIMARY KEY, codigo TEXT, nombre TEXT)',
            'CREATE TABLE sedes (id INTEGER PRIMARY KEY, nombre TEXT)',
            'CREATE TABLE distritos (ubigeo TEXT PRIMARY KEY, nombre_mostrado TEXT)',
            'CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombres TEXT, rol TEXT, id_sede INTEGER, id_tipo_vehiculo INTEGER, placa TEXT, activo INTEGER)',
            'CREATE TABLE lotes (id INTEGER PRIMARY KEY, id_sede INTEGER, id_conductor INTEGER, id_tipo_vehiculo INTEGER, despachado_por INTEGER, despachado_at TEXT)',
            'CREATE TABLE paquetes (id INTEGER PRIMARY KEY, tracking_id TEXT, id_paquete_origen INTEGER, id_sede INTEGER, ubigeo_destino TEXT, estado_actual_id INTEGER, id_lote INTEGER, fecha_ingreso TEXT, creado_por INTEGER)',
            'CREATE TABLE historial_estados (id INTEGER PRIMARY KEY, id_paquete INTEGER, estado_nuevo_id INTEGER, id_motivo_fallo INTEGER, created_at TEXT)',
        ] as $sql) {
            $this->db->unprepared($sql);
        }

        $this->db->table('estados_paquete')->insert([
            ['id' => 1, 'codigo' => 'EN_ALMACEN', 'nombre' => 'En Almacén'],
            ['id' => 2, 'codigo' => 'ASIGNADO', 'nombre' => 'Asignado'],
            ['id' => 3, 'codigo' => 'EN_RUTA', 'nombre' => 'En Ruta'],
            ['id' => 4, 'codigo' => 'ENTREGADO', 'nombre' => 'Entregado'],
            ['id' => 5, 'codigo' => 'FALLIDO', 'nombre' => 'Fallido'],
        ]);
        $this->db->table('motivos_fallo')->insert([
            ['id' => 1, 'codigo' => 'CLIENTE_AUSENTE', 'nombre' => 'Cliente ausente'],
            ['id' => 2, 'codigo' => 'DIRECCION_ERRONEA', 'nombre' => 'Dirección errónea'],
        ]);
        $this->db->table('tipos_vehiculo')->insert(['id' => 1, 'codigo' => 'MOTORIZADO', 'nombre' => 'Motorizado']);
        $this->db->table('sedes')->insert([['id' => 1, 'nombre' => 'Sede Norte'], ['id' => 2, 'nombre' => 'Sede Sur']]);
        $this->db->table('distritos')->insert(['ubigeo' => '150122', 'nombre_mostrado' => 'Miraflores']);
        $this->db->table('usuarios')->insert([
            ['id' => 21, 'nombres' => 'Lucía Vega', 'rol' => 'OPERADOR', 'id_sede' => 1, 'id_tipo_vehiculo' => null, 'placa' => null, 'activo' => 1],
            ['id' => 22, 'nombres' => 'Ana Torres', 'rol' => 'OPERADOR', 'id_sede' => 2, 'id_tipo_vehiculo' => null, 'placa' => null, 'activo' => 1],
            ['id' => 11, 'nombres' => 'Luis Ramos', 'rol' => 'CONDUCTOR', 'id_sede' => null, 'id_tipo_vehiculo' => 1, 'placa' => 'ABC-11', 'activo' => 1],
        ]);
        $this->db->table('lotes')->insert([
            ['id' => 10, 'id_sede' => 1, 'id_conductor' => 11, 'id_tipo_vehiculo' => 1, 'despachado_por' => 21, 'despachado_at' => '2026-10-10 17:00:00'],
            ['id' => 11, 'id_sede' => 1, 'id_conductor' => 11, 'id_tipo_vehiculo' => 1, 'despachado_por' => 21, 'despachado_at' => '2026-11-02 12:00:00'],
        ]);

        // Fechas en UTC; Lima es UTC-5.
        foreach ([
            [1, null, 1, 4, 10, '2026-10-10 13:00:00', 21],
            [2, null, 1, 5, 10, '2026-10-10 15:00:00', 21],
            [3, 2, 1, 4, 11, '2026-10-12 15:00:00', 21],    // reintento del 2, con la dirección corregida
            [4, null, 2, 1, null, '2026-10-01 04:59:59', 22], // 30/09 23:59 en Lima: fuera de octubre
            [5, null, 2, 1, null, '2026-10-01 05:00:00', 22], // 01/10 00:00 en Lima
            [6, null, 1, 5, null, '2026-09-20 15:00:00', 21],
            [7, null, 2, 1, null, '2026-11-01 04:59:59', 22], // 31/10 23:59 en Lima
        ] as [$id, $origin, $sede, $state, $batch, $registeredAt, $operator]) {
            $this->db->table('paquetes')->insert([
                'id' => $id, 'tracking_id' => 'TRK' . $id, 'id_paquete_origen' => $origin, 'id_sede' => $sede,
                'ubigeo_destino' => '150122', 'estado_actual_id' => $state, 'id_lote' => $batch,
                'fecha_ingreso' => $registeredAt, 'creado_por' => $operator,
            ]);
        }

        $this->db->table('historial_estados')->insert([
            ['id' => 1, 'id_paquete' => 1, 'estado_nuevo_id' => 2, 'id_motivo_fallo' => null, 'created_at' => '2026-10-10 17:00:00'],
            ['id' => 2, 'id_paquete' => 1, 'estado_nuevo_id' => 4, 'id_motivo_fallo' => null, 'created_at' => '2026-10-11 01:00:00'],
            ['id' => 3, 'id_paquete' => 2, 'estado_nuevo_id' => 5, 'id_motivo_fallo' => 2, 'created_at' => '2026-10-11 02:00:00'],
            ['id' => 4, 'id_paquete' => 3, 'estado_nuevo_id' => 4, 'id_motivo_fallo' => null, 'created_at' => '2026-10-13 15:00:00'],
            ['id' => 5, 'id_paquete' => 6, 'estado_nuevo_id' => 5, 'id_motivo_fallo' => 1, 'created_at' => '2026-11-01 05:00:00'],
        ]);

        $this->repository = new MySqlDashboardReadRepository($this->db);
    }

    private function october(?int $sedeId = null): DashboardScope
    {
        return new DashboardScope(self::OCTOBER[0], self::OCTOBER[1], $sedeId);
    }

    /** @return list<array{int, int, string, ?string, int}> */
    private function registrationRows(DashboardScope $scope): array
    {
        $groups = array_map(
            static fn (RegistrationGroup $g): array => [$g->sedeId, $g->operatorId, $g->status, $g->failureReason, $g->count],
            $this->repository->registrationGroups($scope),
        );
        sort($groups);

        return $groups;
    }

    public function test_agrupa_los_registrados_por_dia_de_lima_sede_operador_y_estado(): void
    {
        self::assertSame([
            [1, 21, 'ENTREGADO', null, 2],
            [1, 21, 'FALLIDO', 'DIRECCION_ERRONEA', 1],
            [2, 22, 'EN_ALMACEN', null, 2],
        ], $this->registrationRows($this->october()));
        self::assertSame([[2, 22, 'EN_ALMACEN', null, 2]], $this->registrationRows($this->october(2)));
    }

    public function test_lee_las_entregas_y_fallos_del_periodo_con_conductor_vehiculo_y_reintento(): void
    {
        $events = $this->repository->terminalEvents($this->october());

        self::assertSame([1, 2, 3], array_map(static fn ($e): int => $e->packageId, $events));

        [$delivered, $failed] = $events;
        self::assertSame('ENTREGADO', $delivered->status);
        self::assertSame(11, $delivered->driverId);
        self::assertSame('Luis Ramos', $delivered->driverName);
        self::assertSame('ABC-11', $delivered->driverPlate);
        self::assertSame('MOTORIZADO', $delivered->driverVehicleType);
        self::assertSame('MOTORIZADO', $delivered->batchVehicleType);
        self::assertSame(12 * 3600, $delivered->occurredAt->getTimestamp() - $delivered->registeredAt->getTimestamp());
        self::assertFalse($delivered->retried);

        self::assertSame('FALLIDO', $failed->status);
        self::assertSame('DIRECCION_ERRONEA', $failed->failureReason);
        self::assertTrue($failed->retried);
        self::assertSame('ENTREGADO', $failed->retryStatus);

        self::assertSame([], $this->repository->terminalEvents($this->october(2)));
    }

    public function test_lee_los_paquetes_de_los_lotes_despachados_en_el_periodo(): void
    {
        $packages = $this->repository->dispatchedPackages($this->october());

        self::assertCount(2, $packages);
        self::assertSame([21, 21], array_map(static fn ($p): int => $p->operatorId, $packages));
        self::assertSame('2026-10-10 17:00:00', $packages[0]->dispatchedAt->format('Y-m-d H:i:s'));
    }

    public function test_lista_los_operadores_de_todas_las_sedes_o_de_una(): void
    {
        $all = $this->repository->operators(null);

        self::assertSame([22, 21], array_map(static fn ($o): int => $o->id, $all));
        self::assertSame('Sede Sur', $all[0]->sedeName);
        self::assertTrue($all[0]->active);
        self::assertSame([21], array_map(static fn ($o): int => $o->id, $this->repository->operators(1)));
    }

    public function test_lee_el_catalogo_de_estados_una_sola_vez_por_instancia(): void
    {
        $this->db->enableQueryLog();

        $this->repository->registrationGroups($this->october());
        $this->repository->terminalEvents($this->october());
        $this->repository->failedDeliveries($this->october(), 1, 3);
        $this->repository->countFailedDeliveries($this->october());

        $stateLookups = array_filter(
            $this->db->getQueryLog(),
            static fn (array $entry): bool => str_contains($entry['query'], 'from "estados_paquete"'),
        );
        self::assertCount(1, $stateLookups);
    }

    public function test_pagina_los_fallidos_del_periodo_del_mas_reciente_al_mas_antiguo(): void
    {
        $failures = $this->repository->failedDeliveries($this->october(), 1, 8);

        self::assertSame(1, $this->repository->countFailedDeliveries($this->october()));
        self::assertCount(1, $failures);
        self::assertSame(2, $failures[0]->packageId);
        self::assertSame('TRK2', $failures[0]->trackingId);
        self::assertSame('Miraflores', $failures[0]->district);
        self::assertSame('Sede Norte', $failures[0]->sedeName);
        self::assertSame('DIRECCION_ERRONEA', $failures[0]->reasonCode);
        self::assertSame('2026-10-11 02:00:00', $failures[0]->failedAt->format('Y-m-d H:i:s'));

        // 01/11 00:00 en Lima pertenece a noviembre, no a octubre.
        self::assertSame(1, $this->repository->countFailedDeliveries(new DashboardScope('2026-11-01', '2026-11-30')));
        self::assertSame(0, $this->repository->countFailedDeliveries($this->october(2)));
        self::assertSame([], $this->repository->failedDeliveries($this->october(), 2, 8));
    }
}
