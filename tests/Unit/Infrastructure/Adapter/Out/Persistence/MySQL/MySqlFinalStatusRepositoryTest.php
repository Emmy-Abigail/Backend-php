<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Exception\FinalStatusConflict;
use App\Application\Exception\InvalidFinalStatus;
use App\Application\Exception\InvalidPackageTransition;
use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Delivery\EvidencePhoto;
use App\Application\Port\In\Delivery\FinalStatusCommand;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlFinalStatusRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\DeliveryDatabaseFixture;
use Tests\Support\DeliveryPhotoFixture;

require_once __DIR__ . '/../../../../../../Support/DeliveryDatabaseFixture.php';
require_once __DIR__ . '/../../../../../../Support/DeliveryPhotoFixture.php';

/** SQLite verifica persistencia y rollback; la serializacion se prueba aparte con MySQL. */
final class MySqlFinalStatusRepositoryTest extends TestCase
{
    private Connection $db;
    private MySqlFinalStatusRepository $repository;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db = $capsule->getConnection();
        DeliveryDatabaseFixture::create($this->db);
        $this->repository = new MySqlFinalStatusRepository($this->db);
    }

    private function command(int $package = 1, string $state = 'FALLIDO', ?int $reason = 1): FinalStatusCommand
    {
        return new FinalStatusCommand(new AuthenticatedUser(31, 'Juan', 'juan@example.invalid', 'CONDUCTOR'), $package, $state,
            $state === 'FALLIDO' ? $reason : null, ' Cliente ausente ',
            $state === 'ENTREGADO' ? new EvidencePhoto(DeliveryPhotoFixture::bytes()) : null);
    }

    private function snapshot(): array
    {
        return array_map(fn (string $table): array => $this->db->table($table)->orderBy('id')->get()->toArray(), ['lotes', 'paquetes', 'historial_estados']);
    }

    public function test_fallo_guarda_motivo_observacion_e_historial_sin_cerrar_con_paradas_pendientes(): void
    {
        $result = $this->repository->finalize($this->command(), null);
        self::assertSame('FALLIDO', $result->status);
        self::assertFalse($result->batchClosed);
        self::assertFalse($result->hasEvidence);
        self::assertSame(5, $this->db->table('paquetes')->where('id', 1)->value('estado_actual_id'));
        $history = $this->db->table('historial_estados')->first();
        self::assertSame(3, $history->estado_anterior_id);
        self::assertSame(5, $history->estado_nuevo_id);
        self::assertSame(31, $history->id_usuario_ejecutor);
        self::assertSame(1, $history->id_motivo_fallo);
        self::assertSame('Cliente ausente', $history->comentario);
        self::assertNull($history->evidencia_key);
        self::assertSame($result->occurredAt->format('Y-m-d H:i:s'), $history->created_at);
        self::assertNull($this->db->table('lotes')->where('id', 210)->value('closed_at'));
        self::assertSame([3, 2, 2], $this->db->table('paquetes')->where('id', '>', 1)->orderBy('id')->pluck('estado_actual_id')->all());
        self::assertSame([2, 1], $this->db->table('paquetes')->where('id_lote', 210)->orderBy('id')->pluck('orden_parada')->all());
    }

    public static function finalCombinations(): array
    {
        return [['FALLIDO', 'ENTREGADO'], ['ENTREGADO', 'FALLIDO'], ['ENTREGADO', 'ENTREGADO'], ['FALLIDO', 'FALLIDO']];
    }

    #[DataProvider('finalCombinations')]
    public function test_cierra_solo_despues_de_la_ultima_parada_con_cualquier_combinacion_terminal(string $first, string $last): void
    {
        foreach ([1 => $first, 2 => $last] as $id => $status) {
            $key = $status === 'ENTREGADO' ? str_repeat((string) $id, 64) . '.png' : null;
            $result = $this->repository->finalize($this->command($id, $status), $key);
            self::assertSame($id === 2, $result->batchClosed);
        }
        $batch = $this->db->table('lotes')->where('id', 210)->first();
        self::assertSame('CERRADO', $batch->estado);
        self::assertSame($result->occurredAt->format('Y-m-d H:i:s'), $batch->closed_at);
        self::assertSame(2, $this->db->table('historial_estados')->count());
        self::assertSame(['ASIGNADO', 'ASIGNADO'], $this->db->table('lotes')->whereIn('id', [211, 212])->orderBy('id')->pluck('estado')->all());
        self::assertStringEndsWith('-05:00', $result->toArray()['lote']['closed_at']);
        self::assertStringEndsWith('-05:00', $result->toArray()['fecha']);
        self::assertArrayNotHasKey('evidencia_key', $result->toArray());
        if ($last === 'ENTREGADO') {
            self::assertTrue($this->repository->isEvidenceReferenced(str_repeat('2', 64) . '.png'));
        }
    }

    public function test_no_permite_doble_clic_ni_cambiar_un_estado_terminal(): void
    {
        $this->repository->finalize($this->command(), null);
        $before = $this->snapshot();
        foreach (['FALLIDO', 'ENTREGADO'] as $status) {
            try {
                $this->repository->finalize($this->command(1, $status), $status === 'ENTREGADO' ? str_repeat('f', 64) . '.png' : null);
                self::fail('Se modifico un paquete finalizado');
            } catch (FinalStatusConflict) {
                self::assertEquals($before, $this->snapshot());
            }
        }
    }

    public static function rejections(): array
    {
        return [
            ['missing', PackageNotFound::class], ['foreign', PackageAccessDenied::class],
            ['unassigned', PackageAccessDenied::class], ['assigned', InvalidPackageTransition::class],
            ['warehouse', InvalidPackageTransition::class], ['closed', InvalidPackageTransition::class],
            ['inactive', UserNotAllowed::class], ['password', UserNotAllowed::class],
            ['role', UserNotAllowed::class], ['reason', InvalidFinalStatus::class],
        ];
    }

    #[DataProvider('rejections')]
    public function test_prevalida_y_revalida_bajo_bloqueo_sin_guardar_parcialmente(string $scenario, string $expected): void
    {
        match ($scenario) {
            'foreign' => $this->db->table('lotes')->where('id', 210)->update(['id_conductor' => 32]),
            'unassigned' => $this->db->table('lotes')->where('id', 210)->update(['id_conductor' => null]),
            'assigned' => $this->db->table('lotes')->where('id', 210)->update(['estado' => 'ASIGNADO']),
            'closed' => $this->db->table('lotes')->where('id', 210)->update(['estado' => 'CERRADO']),
            'warehouse' => $this->db->table('paquetes')->where('id', 1)->update(['estado_actual_id' => 1]),
            'inactive' => $this->db->table('usuarios')->where('id', 31)->update(['activo' => false]),
            'password' => $this->db->table('usuarios')->where('id', 31)->update(['debe_cambiar_password' => true]),
            'role' => $this->db->table('usuarios')->where('id', 31)->update(['rol' => 'OPERADOR']),
            default => null,
        };
        $command = $this->command($scenario === 'missing' ? 999 : 1, 'FALLIDO', $scenario === 'reason' ? 99 : 1);
        $before = $this->snapshot();
        foreach (['assertCanFinalize', 'finalize'] as $method) {
            try {
                $method === 'finalize' ? $this->repository->finalize($command, null) : $this->repository->assertCanFinalize($command);
                self::fail('Se acepto una finalizacion invalida');
            } catch (\RuntimeException $exception) {
                self::assertInstanceOf($expected, $exception);
            }
            self::assertEquals($before, $this->snapshot());
        }
    }

    public function test_fallo_de_historial_revierte_el_estado_y_el_cierre(): void
    {
        $this->repository->finalize($this->command(), null);
        $this->db->unprepared("CREATE TRIGGER fail_delivery_history BEFORE INSERT ON historial_estados BEGIN SELECT RAISE(ABORT, 'history failure'); END");
        $before = $this->snapshot();
        try {
            $this->repository->finalize($this->command(2), null);
            self::fail('No fallo el historial');
        } catch (QueryException) {
            self::assertEquals($before, $this->snapshot());
            self::assertSame(0, $this->db->transactionLevel());
        }
    }

    public function test_fallo_al_cerrar_revierte_tambien_el_ultimo_evento(): void
    {
        $this->repository->finalize($this->command(), null);
        $this->db->unprepared("CREATE TRIGGER fail_delivery_close BEFORE UPDATE ON lotes WHEN NEW.estado = 'CERRADO' BEGIN SELECT RAISE(ABORT, 'closing failure'); END");
        $before = $this->snapshot();
        try {
            $this->repository->finalize($this->command(2), null);
            self::fail('No fallo el cierre');
        } catch (QueryException) {
            self::assertEquals($before, $this->snapshot());
        }
    }
}
