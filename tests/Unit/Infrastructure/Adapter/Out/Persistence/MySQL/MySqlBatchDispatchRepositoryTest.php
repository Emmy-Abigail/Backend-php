<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Exception\BatchWeightExceeded;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\DispatchBatchCommand;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlBatchDispatchRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\RouteDatabaseFixture;

require_once __DIR__ . '/../../../../../../Support/RouteDatabaseFixture.php';

/** Pruebas de guardado y rechazo con consultas reales en SQLite en memoria. */
final class MySqlBatchDispatchRepositoryTest extends TestCase
{
    private Connection $db;
    private MySqlBatchDispatchRepository $repository;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        RouteDatabaseFixture::create($this->db);
        foreach ([
            'ALTER TABLE usuarios ADD COLUMN id_tipo_vehiculo INTEGER DEFAULT 1',
            "ALTER TABLE usuarios ADD COLUMN nombres TEXT DEFAULT 'Conductor de prueba'",
            "ALTER TABLE usuarios ADD COLUMN placa TEXT DEFAULT 'TEST31'",
            'ALTER TABLE lotes ADD COLUMN id_sede INTEGER DEFAULT 1',
            'ALTER TABLE lotes ADD COLUMN id_tipo_vehiculo INTEGER DEFAULT 1',
            'ALTER TABLE lotes ADD COLUMN version INTEGER DEFAULT 0',
            'ALTER TABLE lotes ADD COLUMN despachado_por INTEGER',
            'ALTER TABLE lotes ADD COLUMN despachado_at TEXT',
            "ALTER TABLE paquetes ADD COLUMN peso_kg NUMERIC DEFAULT '1.00'",
            "ALTER TABLE paquetes ADD COLUMN ubigeo_destino TEXT DEFAULT '150122'",
            "ALTER TABLE paquetes ADD COLUMN tracking_id TEXT DEFAULT 'TEST000001'",
            "ALTER TABLE paquetes ADD COLUMN fecha_ingreso TEXT DEFAULT '2026-10-24 10:00:00'",
            'CREATE TABLE tipos_vehiculo (id INTEGER PRIMARY KEY, peso_max_total_kg NUMERIC)',
            'CREATE TABLE distritos (ubigeo TEXT PRIMARY KEY, nombre_mostrado TEXT)',
        ] as $sql) {
            $this->db->unprepared($sql);
        }
        $this->db->table('tipos_vehiculo')->insert(['id' => 1, 'peso_max_total_kg' => '20.00']);
        $this->db->table('distritos')->insert(['ubigeo' => '150122', 'nombre_mostrado' => 'Miraflores']);
        $this->db->table('lotes')->where('id', 210)->update(['estado' => 'EN_PREPARACION', 'id_conductor' => null]);
        $this->db->table('paquetes')->where('id_lote', 210)->update(['estado_actual_id' => 1, 'orden_parada' => null]);
        $this->repository = new MySqlBatchDispatchRepository($this->db);
    }

    private function command(): DispatchBatchCommand
    {
        return new DispatchBatchCommand(new AuthenticatedUser(5, 'Operador', 'operator@example.invalid', 'OPERADOR', false, 1), 210, 31, 0);
    }

    private function setWeights(string $first, string $second, string $capacity = '20.00'): void
    {
        $this->db->table('paquetes')->where('id', 1)->update(['peso_kg' => $first]);
        $this->db->table('paquetes')->where('id', 2)->update(['peso_kg' => $second]);
        $this->db->table('tipos_vehiculo')->where('id', 1)->update(['peso_max_total_kg' => $capacity]);
    }

    private function snapshotTables(): array
    {
        return array_map(fn (string $table): array => $this->db->table($table)->orderBy('id')->get()->toArray(), ['lotes', 'paquetes', 'historial_estados']);
    }

    public static function overweightBatches(): array
    {
        return [
            '24 kg en moto de 20 kg' => ['12.00', '12.00', '20.00'],
            'una centesima de exceso' => ['10.00', '10.01', '20.00'],
            'limite decimal' => ['0.10', '0.21', '0.30'],
        ];
    }

    #[DataProvider('overweightBatches')]
    public function test_rechaza_exceso_sin_asignar_conductor_ni_cambiar_paradas_estados_o_historial(string $first, string $second, string $capacity): void
    {
        $this->setWeights($first, $second, $capacity);
        $snapshot = $this->repository->snapshot(210, 1);
        $before = $this->snapshotTables();
        try {
            $this->repository->dispatch($this->command(), $snapshot, [2, 1]);
            self::fail('Se despacho un lote que excede el peso permitido');
        } catch (BatchWeightExceeded $exception) {
            self::assertSame('El peso total del lote supera la capacidad del vehiculo', $exception->getMessage());
        }
        self::assertEquals($before, $this->snapshotTables());
        self::assertSame(0, $this->db->transactionLevel());
    }

    public static function batchesWithinCapacity(): array
    {
        return [
            'peso menor' => ['9.00', '10.99', '20.00'],
            'peso exactamente igual' => ['9.99', '10.01', '20.00'],
            'decimales exactos sin float' => ['0.10', '0.20', '0.30'],
        ];
    }

    #[DataProvider('batchesWithinCapacity')]
    public function test_despacha_hasta_el_limite_exacto_con_paradas_e_historial(string $first, string $second, string $capacity): void
    {
        $this->setWeights($first, $second, $capacity);
        $result = $this->repository->dispatch($this->command(), $this->repository->snapshot(210, 1), [2, 1]);

        self::assertSame('ASIGNADO', $result->toArray()['estado']);
        self::assertSame([2, 1], array_column($result->stops, 'id_paquete'));
        $batch = $this->db->table('lotes')->where('id', 210)->first();
        self::assertSame(31, $batch->id_conductor);
        self::assertSame(1, $batch->version);
        self::assertSame([2, 2], $this->db->table('paquetes')->where('id_lote', 210)->pluck('estado_actual_id')->all());
        self::assertSame(2, $this->db->table('historial_estados')->count());
    }

    public function test_usa_la_capacidad_vigente_aunque_haya_cambiado_despues_de_pedir_la_ruta(): void
    {
        $this->setWeights('10.00', '10.00');
        $snapshot = $this->repository->snapshot(210, 1);
        $this->db->table('tipos_vehiculo')->where('id', 1)->update(['peso_max_total_kg' => '19.99']);
        $before = $this->snapshotTables();
        try {
            $this->repository->dispatch($this->command(), $snapshot, [2, 1]);
            self::fail('Se despacho usando una capacidad anterior');
        } catch (BatchWeightExceeded) {
            self::assertEquals($before, $this->snapshotTables());
        }
    }

    public function test_vuelve_a_leer_los_pesos_aunque_los_ids_de_paquetes_sean_los_mismos(): void
    {
        $this->setWeights('10.00', '10.00');
        $snapshot = $this->repository->snapshot(210, 1);
        $this->db->table('paquetes')->where('id', 1)->update(['peso_kg' => '10.01']);
        $before = $this->snapshotTables();
        try {
            $this->repository->dispatch($this->command(), $snapshot, [2, 1]);
            self::fail('No se revalido el peso actual de los paquetes');
        } catch (BatchWeightExceeded) {
            self::assertEquals($before, $this->snapshotTables());
        }
    }
}
