<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Exception\PackageAccessDenied;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\GetPackageQuery;
use App\Application\Service\Package\GetPackageService;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlPackageQueryRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

/** Ejecuta consultas reales en SQLite en memoria; no accede a la BD del proyecto. */
final class MySqlPackageQueryRepositoryTest extends TestCase
{
    private Connection $db;
    private MySqlPackageQueryRepository $repository;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        foreach ([
            'CREATE TABLE estados_paquete (id INTEGER PRIMARY KEY, codigo TEXT, nombre TEXT)',
            'CREATE TABLE distritos (ubigeo TEXT PRIMARY KEY, nombre_mostrado TEXT)',
            'CREATE TABLE sedes (id INTEGER PRIMARY KEY, nombre TEXT)',
            'CREATE TABLE clientes (id INTEGER PRIMARY KEY, tipo_documento TEXT, numero_documento TEXT, nombres_razon_social TEXT, celular TEXT)',
            'CREATE TABLE tipos_vehiculo (id INTEGER PRIMARY KEY, codigo TEXT, nombre TEXT)',
            'CREATE TABLE lotes (id INTEGER PRIMARY KEY, codigo TEXT, estado TEXT, id_conductor INTEGER, id_tipo_vehiculo INTEGER)',
            'CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombres TEXT, rol TEXT, placa TEXT)',
            'CREATE TABLE motivos_fallo (id INTEGER PRIMARY KEY, codigo TEXT, nombre TEXT)',
            'CREATE TABLE historial_estados (id INTEGER PRIMARY KEY, id_paquete INTEGER, estado_anterior_id INTEGER, estado_nuevo_id INTEGER, id_usuario_ejecutor INTEGER, id_motivo_fallo INTEGER, comentario TEXT, evidencia_key TEXT, created_at TEXT)',
            'CREATE TABLE paquetes (id INTEGER PRIMARY KEY, tracking_id TEXT, id_sede INTEGER, id_remitente INTEGER, destinatario_nombres TEXT, destinatario_dni TEXT, destinatario_celular TEXT, ubigeo_destino TEXT, tipo_via TEXT, nombre_via TEXT, numero TEXT, manzana TEXT, lote_predio TEXT, referencia TEXT, peso_kg NUMERIC, largo_cm NUMERIC, ancho_cm NUMERIC, alto_cm NUMERIC, costo_estimado NUMERIC, estado_actual_id INTEGER, id_lote INTEGER, orden_parada INTEGER, fecha_ingreso TEXT)',
        ] as $sql) {
            $this->db->unprepared($sql);
        }
        $this->db->table('estados_paquete')->insert([
            ['id' => 1, 'codigo' => 'EN_ALMACEN', 'nombre' => 'En almacen'],
            ['id' => 2, 'codigo' => 'ASIGNADO', 'nombre' => 'Asignado'],
            ['id' => 3, 'codigo' => 'FALLIDO', 'nombre' => 'Fallido'],
            ['id' => 4, 'codigo' => 'ENTREGADO', 'nombre' => 'Entregado'],
            ['id' => 5, 'codigo' => 'EN_RUTA', 'nombre' => 'En ruta'],
        ]);
        $this->db->table('distritos')->insert(['ubigeo' => '150122', 'nombre_mostrado' => 'Miraflores']);
        $this->db->table('sedes')->insert(['id' => 1, 'nombre' => 'Sede Norte']);
        $this->db->table('clientes')->insert(['id' => 1, 'tipo_documento' => 'DNI', 'numero_documento' => '12345678', 'nombres_razon_social' => 'Remitente', 'celular' => '912345678']);
        $this->db->table('tipos_vehiculo')->insert(['id' => 1, 'codigo' => 'MOTORIZADO', 'nombre' => 'Motorizado']);
        $this->db->table('usuarios')->insert(['id' => 7, 'nombres' => 'Conductor', 'rol' => 'CONDUCTOR', 'placa' => 'TEST01']);
        $this->db->table('lotes')->insert(['id' => 10, 'codigo' => 'L-10', 'estado' => 'ASIGNADO', 'id_conductor' => 7, 'id_tipo_vehiculo' => 1]);
        foreach ([
            1 => '2026-10-24 04:59:59', 2 => '2026-10-24 05:00:00',
            3 => '2026-10-25 04:59:59', 4 => '2026-10-25 05:00:00',
            5 => '2026-10-24 15:00:00',
        ] as $id => $date) {
            $this->db->table('paquetes')->insert([
                'id' => $id, 'tracking_id' => 'TEST' . $id, 'id_sede' => $id === 5 ? 2 : 1,
                'id_remitente' => 1, 'destinatario_nombres' => 'Cliente ' . $id,
                'destinatario_dni' => '41234567', 'destinatario_celular' => '912345678',
                'ubigeo_destino' => '150122', 'tipo_via' => 'Av.', 'nombre_via' => 'Prueba',
                'numero' => '123', 'peso_kg' => 1, 'largo_cm' => 20, 'ancho_cm' => 20, 'alto_cm' => 20,
                'costo_estimado' => 10, 'estado_actual_id' => 2, 'id_lote' => 10,
                'orden_parada' => $id, 'fecha_ingreso' => $date,
            ]);
        }
        $this->repository = new MySqlPackageQueryRepository($this->db);
    }

    private function search(?string $from = null, ?string $to = null, int $page = 1, int $perPage = 20): array
    {
        return $this->repository->searchBySede(1, null, null, $from, $to, $page, $perPage)->toArray();
    }

    public function test_filtra_el_dia_completo_de_lima_en_los_limites_utc(): void
    {
        $data = $this->search('2026-10-24', '2026-10-24');
        self::assertSame([3, 2], array_column($data['data'], 'id'));
        self::assertSame('2026-10-24T23:59:59-05:00', $data['data'][0]['fecha_ingreso']);
        self::assertSame('2026-10-24T00:00:00-05:00', $data['data'][1]['fecha_ingreso']);
        self::assertSame(2, $data['meta']['total']);
    }

    public function test_aplica_por_separado_desde_y_hasta(): void
    {
        self::assertSame([4, 3, 2], array_column($this->search('2026-10-24')['data'], 'id'));
        self::assertSame([3, 2, 1], array_column($this->search(to: '2026-10-24')['data'], 'id'));
    }

    public function test_pagina_solo_paquetes_de_la_sede_y_mantiene_el_total(): void
    {
        $data = $this->search(page: 2, perPage: 2);
        self::assertSame([2, 1], array_column($data['data'], 'id'));
        self::assertSame(['page' => 2, 'per_page' => 2, 'total' => 4, 'total_pages' => 2], $data['meta']);
        self::assertSame([], $this->search(page: 3, perPage: 2)['data']);
    }

    public function test_filtra_nombre_y_estado_sin_incluir_otra_sede(): void
    {
        $data = $this->repository->searchBySede(1, 'Cliente 2', 'ASIGNADO', null, null, 1, 20)->toArray();
        self::assertSame([2], array_column($data['data'], 'id'));
        self::assertSame([], $this->repository->searchBySede(1, 'TEST5', null, null, null, 1, 20)->items);
    }

    public function test_detalle_incluye_el_vehiculo_del_lote_y_fecha_de_lima(): void
    {
        $detail = $this->repository->findDetail(2);
        self::assertSame(['id' => 1, 'codigo' => 'MOTORIZADO', 'nombre' => 'Motorizado'], $detail->data['vehiculo']);
        self::assertSame('2026-10-24T00:00:00-05:00', $detail->data['fecha_ingreso']);
        self::assertSame(10, $detail->data['lote']['id']);
    }

    public function test_un_lote_en_preparacion_muestra_vehiculo_sin_conductor(): void
    {
        $this->db->table('lotes')->where('id', 10)->update(['estado' => 'EN_PREPARACION', 'id_conductor' => null]);
        $detail = $this->repository->findDetail(2);
        self::assertSame('MOTORIZADO', $detail->data['vehiculo']['codigo']);
        self::assertNull($detail->data['lote']['conductor']);
    }

    public function test_un_paquete_sin_lote_no_se_pierde_por_el_join_del_vehiculo(): void
    {
        $this->db->table('paquetes')->where('id', 2)->update(['id_lote' => null]);
        $detail = $this->repository->findDetail(2);
        self::assertNotNull($detail);
        self::assertNull($detail->data['vehiculo']);
        self::assertNull($detail->data['lote']);
        self::assertNull($this->repository->findDetail(999));
    }

    public function test_historial_cronologico_con_fecha_lima_motivo_y_evidencia_privada(): void
    {
        $this->db->table('motivos_fallo')->insert(['id' => 1, 'codigo' => 'AUSENTE', 'nombre' => 'Cliente ausente']);
        $base = ['id_paquete' => 2, 'id_usuario_ejecutor' => 7, 'id_motivo_fallo' => null, 'comentario' => null, 'evidencia_key' => null];
        $this->db->table('historial_estados')->insert([
            $base + ['id' => 2, 'estado_anterior_id' => 1, 'estado_nuevo_id' => 2, 'created_at' => '2026-10-24 06:00:00'],
            $base + ['id' => 1, 'estado_anterior_id' => null, 'estado_nuevo_id' => 1, 'created_at' => '2026-10-24 05:00:00'],
            $base + ['id' => 3, 'estado_anterior_id' => 2, 'estado_nuevo_id' => 5, 'created_at' => '2026-10-24 06:00:00'],
            array_replace($base, ['id' => 4, 'estado_anterior_id' => 5, 'estado_nuevo_id' => 3, 'created_at' => '2026-10-24 07:00:00', 'id_motivo_fallo' => 1, 'comentario' => 'No respondio']),
            array_replace($base, ['id' => 5, 'id_paquete' => 3, 'estado_anterior_id' => 5, 'estado_nuevo_id' => 4, 'created_at' => '2026-10-24 07:00:00', 'evidencia_key' => 'private/test.jpg']),
        ]);
        $history = $this->repository->findDetail(2)->history;
        self::assertSame(['EN_ALMACEN', 'ASIGNADO', 'EN_RUTA', 'FALLIDO'], array_column($history, 'estado_nuevo'));
        self::assertSame('2026-10-24T00:00:00-05:00', $history[0]['fecha']);
        self::assertSame('No respondio', $history[3]['comentario']);
        self::assertSame('AUSENTE', $history[3]['motivo_fallo']['codigo']);
        $delivered = $this->repository->findDetail(3)->history[0];
        self::assertTrue($delivered['tiene_evidencia']);
        self::assertArrayNotHasKey('evidencia_key', $delivered);
    }

    public function test_tracking_publico_muestra_estado_distrito_y_linea_de_tiempo_sin_datos_personales(): void
    {
        $this->db->table('motivos_fallo')->insert(['id' => 1, 'codigo' => 'AUSENTE', 'nombre' => 'Cliente ausente']);
        $this->db->table('paquetes')->where('id', 2)->update(['estado_actual_id' => 3]);
        $base = ['id_paquete' => 2, 'id_usuario_ejecutor' => 7, 'id_motivo_fallo' => null, 'comentario' => null, 'evidencia_key' => null];
        $this->db->table('historial_estados')->insert([
            $base + ['id' => 1, 'estado_anterior_id' => null, 'estado_nuevo_id' => 1, 'created_at' => '2026-10-24 05:00:00'],
            $base + ['id' => 2, 'estado_anterior_id' => 1, 'estado_nuevo_id' => 2, 'created_at' => '2026-10-24 06:00:00'],
            $base + ['id' => 3, 'estado_anterior_id' => 2, 'estado_nuevo_id' => 5, 'created_at' => '2026-10-24 06:30:00'],
            array_replace($base, ['id' => 4, 'estado_anterior_id' => 5, 'estado_nuevo_id' => 3, 'created_at' => '2026-10-24 07:00:00', 'id_motivo_fallo' => 1, 'comentario' => 'Vecino dijo que viajo']),
        ]);

        $tracking = $this->repository->findPublicTracking('TEST2');

        self::assertSame(['tracking_id', 'estado', 'destino', 'fecha_ingreso', 'linea_de_tiempo'], array_keys($tracking));
        self::assertSame('FALLIDO', $tracking['estado']['codigo']);
        self::assertSame(['distrito' => 'Miraflores'], $tracking['destino']);
        self::assertSame('2026-10-24T00:00:00-05:00', $tracking['fecha_ingreso']);
        self::assertSame(['EN_ALMACEN', 'ASIGNADO', 'EN_RUTA', 'FALLIDO'], array_column($tracking['linea_de_tiempo'], 'estado'));
        self::assertSame('2026-10-24T02:00:00-05:00', $tracking['linea_de_tiempo'][3]['fecha']);
        self::assertSame('Cliente ausente', $tracking['linea_de_tiempo'][3]['motivo']);
        self::assertSame(['estado', 'nombre', 'fecha', 'motivo'], array_keys($tracking['linea_de_tiempo'][3]));

        $json = json_encode($tracking);
        foreach (['Cliente 2', '41234567', '912345678', 'Prueba', 'Remitente', 'Conductor', 'TEST01', 'Vecino dijo'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function test_tracking_de_un_codigo_inexistente(): void
    {
        self::assertNull($this->repository->findPublicTracking('NOEXISTE00'));
    }

    public function test_el_conductor_recibe_vehiculo_sin_remitente_ni_costo(): void
    {
        $service = new GetPackageService($this->repository);
        $driver = new AuthenticatedUser(7, 'Conductor', 'driver@example.invalid', 'CONDUCTOR');
        $data = $service->execute(new GetPackageQuery($driver, 2))->toArray();
        self::assertSame('MOTORIZADO', $data['vehiculo']['codigo']);
        self::assertArrayNotHasKey('remitente', $data);
        self::assertArrayNotHasKey('costo_estimado', $data);
        $this->expectException(PackageAccessDenied::class);
        $service->execute(new GetPackageQuery(new AuthenticatedUser(8, 'Otro', 'other@example.invalid', 'CONDUCTOR'), 2));
    }
}
