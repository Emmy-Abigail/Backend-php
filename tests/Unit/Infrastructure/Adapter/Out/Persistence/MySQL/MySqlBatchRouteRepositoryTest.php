<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Exception\BatchAccessDenied;
use App\Application\Exception\BatchHasNoPackages;
use App\Application\Exception\BatchNotFound;
use App\Application\Exception\InvalidBatchTransition;
use App\Application\Exception\RouteStartConflict;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\StartRouteCommand;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlBatchRouteRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\RouteDatabaseFixture;

require_once __DIR__ . '/../../../../../../Support/RouteDatabaseFixture.php';

/** SQLite comprueba guardado y rollback; los bloqueos se verifican aparte contra MySQL. */
final class MySqlBatchRouteRepositoryTest extends TestCase
{
    private Connection $db;
    private MySqlBatchRouteRepository $repository;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db = $capsule->getConnection();
        RouteDatabaseFixture::create($this->db);
        $this->repository = new MySqlBatchRouteRepository($this->db);
    }

    private function command(int $batch = 210, int $driver = 31): StartRouteCommand
    {
        return new StartRouteCommand(new AuthenticatedUser($driver, 'Juan', 'juan@example.invalid', 'CONDUCTOR'), $batch);
    }

    private function snapshot(): array
    {
        return array_map(fn (string $table): array => $this->db->table($table)->orderBy('id')->get()->toArray(), ['lotes', 'paquetes', 'historial_estados']);
    }

    public function test_guarda_estados_hora_historial_y_conserva_el_orden_y_otros_lotes(): void
    {
        $result = $this->repository->start($this->command());
        $batch = $this->db->table('lotes')->where('id', 210)->first();
        self::assertSame('EN_RUTA', $batch->estado);
        self::assertSame($result->startedAt->format('Y-m-d H:i:s'), $batch->started_at);
        self::assertSame(2, $result->packageCount);
        self::assertSame([3, 3], $this->db->table('paquetes')->where('id_lote', 210)->orderBy('id')->pluck('estado_actual_id')->all());
        self::assertSame([2, 1], $this->db->table('paquetes')->where('id_lote', 210)->orderBy('id')->pluck('orden_parada')->all());
        self::assertSame(2, $this->db->table('historial_estados')->count());
        foreach ($this->db->table('historial_estados')->get() as $event) {
            self::assertSame(2, $event->estado_anterior_id);
            self::assertSame(3, $event->estado_nuevo_id);
            self::assertSame(31, $event->id_usuario_ejecutor);
            self::assertSame($batch->started_at, $event->created_at);
        }
        self::assertSame(['ASIGNADO', 'ASIGNADO'], $this->db->table('lotes')->whereIn('id', [211, 212])->orderBy('id')->pluck('estado')->all());
        self::assertSame([2, 2], $this->db->table('paquetes')->whereIn('id', [3, 4])->orderBy('id')->pluck('estado_actual_id')->all());
    }

    public function test_repetir_el_inicio_no_duplica_historial_ni_modifica_la_hora(): void
    {
        $this->repository->start($this->command());
        $before = $this->snapshot();
        try {
            $this->repository->start($this->command());
            self::fail('Se acepto un segundo inicio');
        } catch (RouteStartConflict $exception) {
            self::assertSame('lote_ya_iniciado', $exception->error);
        }
        self::assertEquals($before, $this->snapshot());
    }

    public function test_no_permite_dos_lotes_en_ruta_pero_si_otro_conductor(): void
    {
        $this->repository->start($this->command());
        $before = $this->snapshot();
        try {
            $this->repository->start($this->command(211));
            self::fail('Se aceptaron dos rutas del mismo conductor');
        } catch (RouteStartConflict $exception) {
            self::assertSame('conductor_en_ruta', $exception->error);
        }
        self::assertEquals($before, $this->snapshot());
        self::assertSame(212, $this->repository->start($this->command(212, 32))->batchId);
    }

    public function test_puede_iniciar_el_siguiente_lote_cuando_el_anterior_esta_cerrado(): void
    {
        $this->db->table('lotes')->where('id', 210)->update(['estado' => 'CERRADO']);
        self::assertSame(211, $this->repository->start($this->command(211))->batchId);
    }

    public static function invalidBatches(): array
    {
        return [
            ['missing', BatchNotFound::class], ['foreign', BatchAccessDenied::class],
            ['preparing', InvalidBatchTransition::class], ['closed', RouteStartConflict::class],
            ['empty', BatchHasNoPackages::class], ['warehousePackage', RouteStartConflict::class],
            ['terminalPackage', RouteStartConflict::class], ['inactive', UserNotAllowed::class],
            ['password', UserNotAllowed::class],
        ];
    }

    #[DataProvider('invalidBatches')]
    public function test_rechaza_inconsistencias_sin_guardado_parcial(string $scenario, string $expected): void
    {
        match ($scenario) {
            'foreign' => $this->db->table('lotes')->where('id', 210)->update(['id_conductor' => 32]),
            'preparing' => $this->db->table('lotes')->where('id', 210)->update(['estado' => 'EN_PREPARACION', 'id_conductor' => null]),
            'closed' => $this->db->table('lotes')->where('id', 210)->update(['estado' => 'CERRADO']),
            'empty' => $this->db->table('paquetes')->where('id_lote', 210)->delete(),
            'warehousePackage' => $this->db->table('paquetes')->where('id', 1)->update(['estado_actual_id' => 1]),
            'terminalPackage' => $this->db->table('paquetes')->where('id', 1)->update(['estado_actual_id' => 4]),
            'inactive' => $this->db->table('usuarios')->where('id', 31)->update(['activo' => false]),
            'password' => $this->db->table('usuarios')->where('id', 31)->update(['debe_cambiar_password' => true]),
            default => null,
        };
        $before = $this->snapshot();
        try {
            $this->repository->start($this->command($scenario === 'missing' ? 999 : 210));
            self::fail('Se acepto un lote invalido');
        } catch (\RuntimeException $exception) {
            self::assertInstanceOf($expected, $exception);
        }
        self::assertEquals($before, $this->snapshot());
    }

    public function test_un_fallo_de_historial_revierte_lote_paquetes_y_eventos(): void
    {
        $this->db->unprepared("CREATE TRIGGER fail_route_history BEFORE INSERT ON historial_estados WHEN NEW.id_paquete = 2 BEGIN SELECT RAISE(ABORT, 'test history failure'); END");
        $before = $this->snapshot();
        try {
            $this->repository->start($this->command());
            self::fail('No fallo la escritura del historial');
        } catch (QueryException) {
            self::assertEquals($before, $this->snapshot());
        }
        self::assertSame(0, $this->db->transactionLevel());
    }
}
