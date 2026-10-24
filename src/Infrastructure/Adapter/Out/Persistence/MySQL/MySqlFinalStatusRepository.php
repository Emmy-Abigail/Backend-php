<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Exception\FinalStatusConflict;
use App\Application\Exception\InvalidFinalStatus;
use App\Application\Exception\InvalidPackageTransition;
use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Delivery\FinalStatusCommand;
use App\Application\Port\In\Delivery\FinalStatusResult;
use App\Application\Port\Out\Delivery\FinalStatusRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

final readonly class MySqlFinalStatusRepository implements FinalStatusRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function assertCanFinalize(FinalStatusCommand $command): void
    {
        $package = $this->connection->table('paquetes')->where('id', $command->packageId)->first();
        if ($package === null) {
            throw new PackageNotFound('El paquete no existe');
        }
        $batch = $package->id_lote === null ? null : $this->connection->table('lotes')->where('id', $package->id_lote)->first();
        $this->validate($command, $package, $batch);
    }

    private function validate(FinalStatusCommand $command, object $package, ?object $batch, bool $locked = false): void
    {
        if ($batch === null || $batch->id_conductor === null || (int) $batch->id_conductor !== $command->driver->id) {
            throw new PackageAccessDenied('El paquete no pertenece a un lote suyo');
        }
        $driverQuery = $this->connection->table('usuarios')->where('id', $command->driver->id);
        $driver = ($locked ? $driverQuery->lockForUpdate() : $driverQuery)->first();
        if ($command->driver->role !== 'CONDUCTOR' || $command->driver->mustChangePassword
            || $driver === null || $driver->rol !== 'CONDUCTOR' || !(bool) $driver->activo || (bool) $driver->debe_cambiar_password) {
            throw new UserNotAllowed('El conductor no esta habilitado');
        }
        $status = $this->connection->table('estados_paquete')->where('id', $package->estado_actual_id)->value('codigo');
        if (in_array($status, ['ENTREGADO', 'FALLIDO'], true)) {
            throw new FinalStatusConflict('El paquete ya tiene un estado final y no puede modificarse');
        }
        if ($batch->estado !== 'EN_RUTA' || $status !== 'EN_RUTA' || $batch->closed_at !== null) {
            throw new InvalidPackageTransition('El lote y el paquete deben estar EN_RUTA');
        }
        if ($command->reasonId !== null && !$this->connection->table('motivos_fallo')->where('id', $command->reasonId)->exists()) {
            throw new InvalidFinalStatus('El id_motivo no existe en el catalogo de motivos de fallo');
        }
    }

    public function finalize(FinalStatusCommand $command, ?string $evidenceKey): FinalStatusResult
    {
        if (($command->status === 'ENTREGADO') !== ($evidenceKey !== null)) {
            throw new InvalidFinalStatus('La evidencia no corresponde al estado solicitado');
        }
        try {
            return $this->connection->transaction(function () use ($command, $evidenceKey): FinalStatusResult {
                // Ubicar el lote sin bloquear el paquete: todos los flujos bloquean lote
                // antes de paquetes. Se verifica de nuevo su pertenencia bajo bloqueo.
                $batchId = $this->connection->table('paquetes')->where('id', $command->packageId)->value('id_lote');
                if ($batchId === null) {
                    $this->assertCanFinalize($command);
                    throw new FinalStatusConflict('El paquete cambio de lote; vuelve a consultar');
                }
                $batch = $this->connection->table('lotes')->where('id', $batchId)->lockForUpdate()->first();
                $package = $this->connection->table('paquetes')->where('id', $command->packageId)->lockForUpdate()->first();
                if ($package === null) {
                    throw new PackageNotFound('El paquete no existe');
                }
                if ((int) $package->id_lote !== (int) $batchId) {
                    throw new FinalStatusConflict('El paquete cambio de lote; vuelve a consultar');
                }
                $this->validate($command, $package, $batch, true);
                $states = $this->connection->table('estados_paquete')->whereIn('codigo', ['EN_RUTA', 'ENTREGADO', 'FALLIDO'])->pluck('id', 'codigo');
                if ($states->count() !== 3) {
                    throw new \RuntimeException('Faltan estados de paquete en el catalogo');
                }
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $timestamp = $now->format('Y-m-d H:i:s');
                $this->connection->table('paquetes')->where('id', $package->id)->update([
                    'estado_actual_id' => (int) $states->get($command->status),
                    'updated_at' => $timestamp,
                ]);
                $this->connection->table('historial_estados')->insert([
                    'id_paquete' => (int) $package->id,
                    'estado_anterior_id' => (int) $states->get('EN_RUTA'),
                    'estado_nuevo_id' => (int) $states->get($command->status),
                    'id_usuario_ejecutor' => $command->driver->id,
                    'id_motivo_fallo' => $command->reasonId,
                    'comentario' => $command->observation === '' ? null : $command->observation,
                    'evidencia_key' => $evidenceKey,
                    'created_at' => $timestamp,
                ]);
                // Una lectura bloqueante ve el ultimo commit de las otras paradas, aun
                // con REPEATABLE READ. El mutex del lote serializa el cierre automatico.
                $packages = $this->connection->table('paquetes')->where('id_lote', $batchId)
                    ->orderBy('id')->lockForUpdate()->get(['estado_actual_id']);
                $terminalIds = [(int) $states->get('ENTREGADO'), (int) $states->get('FALLIDO')];
                $closed = $packages->every(static fn (object $row): bool => in_array((int) $row->estado_actual_id, $terminalIds, true));
                if ($closed) {
                    $this->connection->table('lotes')->where('id', $batchId)->update(['estado' => 'CERRADO', 'closed_at' => $timestamp]);
                }
                return new FinalStatusResult((int) $package->id, (string) $package->tracking_id, $command->status, $now, $evidenceKey !== null, (int) $batchId, $closed);
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new FinalStatusConflict('La entrega esta siendo modificada; vuelve a intentarlo');
            }
            throw $exception;
        }
    }

    public function isEvidenceReferenced(string $key): bool
    {
        return $this->connection->table('historial_estados')->useWritePdo()->where('evidencia_key', $key)->exists();
    }
}
