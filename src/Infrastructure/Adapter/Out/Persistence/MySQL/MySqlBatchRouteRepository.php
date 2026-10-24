<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Exception\BatchAccessDenied;
use App\Application\Exception\BatchHasNoPackages;
use App\Application\Exception\BatchNotFound;
use App\Application\Exception\InvalidBatchTransition;
use App\Application\Exception\RouteStartConflict;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Batch\StartRouteCommand;
use App\Application\Port\In\Batch\StartRouteResult;
use App\Application\Port\Out\Batch\BatchRouteRepository;
use App\Domain\Batch\BatchStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class MySqlBatchRouteRepository implements BatchRouteRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function start(StartRouteCommand $command): StartRouteResult
    {
        try {
            // Reintentar la transacción completa si un despacho concurrente causa un interbloqueo.
            return $this->connection->transaction(fn (): StartRouteResult => $this->startLocked($command), 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new RouteStartConflict('La ruta esta siendo modificada; vuelve a intentarlo');
            }

            throw $exception;
        }
    }

    private function startLocked(StartRouteCommand $command): StartRouteResult
    {
        $batch = $this->connection->table('lotes')->where('id', $command->batchId)->lockForUpdate()->first();
        if ($batch === null) {
            throw new BatchNotFound('El lote no existe');
        }
        if ($batch->id_conductor !== null && (int) $batch->id_conductor !== $command->driver->id) {
            throw new BatchAccessDenied('No puede iniciar lotes de otro conductor');
        }
        if ($batch->estado === BatchStatus::EnPreparacion->value) {
            throw new InvalidBatchTransition('El lote debe estar asignado antes de iniciar la ruta');
        }
        if ($batch->id_conductor === null) {
            throw new BatchAccessDenied('El lote no esta asignado a usted');
        }
        if ($batch->estado !== BatchStatus::Asignado->value || $batch->started_at !== null) {
            throw new RouteStartConflict('El lote ya inicio su ruta o esta cerrado', 'lote_ya_iniciado');
        }

        // La fila del conductor es el mutex compartido por TODOS sus inicios, aunque no
        // tenga aún ningún lote EN_RUTA. Bloquear solo el resultado vacío no los serializa.
        $driver = $this->connection->table('usuarios')->where('id', $command->driver->id)->lockForUpdate()->first();
        if ($driver === null || $driver->rol !== 'CONDUCTOR' || !(bool) $driver->activo || (bool) $driver->debe_cambiar_password) {
            throw new UserNotAllowed('El conductor no esta habilitado para iniciar rutas');
        }
        // Lectura bloqueante: consulta el estado más reciente, incluso con REPEATABLE READ.
        $onRoute = $this->connection->table('lotes')
            ->where('id_conductor', $command->driver->id)
            ->where('estado', BatchStatus::EnRuta->value)
            ->lockForUpdate()->first(['id']);
        if ($onRoute !== null) {
            throw new RouteStartConflict('Ya tiene otro lote en ruta', 'conductor_en_ruta');
        }

        $states = $this->connection->table('estados_paquete')->whereIn('codigo', ['ASIGNADO', 'EN_RUTA'])->pluck('id', 'codigo');
        if (!$states->has('ASIGNADO') || !$states->has('EN_RUTA')) {
            throw new RuntimeException('Faltan estados de paquete en el catalogo');
        }
        $packages = $this->connection->table('paquetes')->where('id_lote', $batch->id)
            ->orderBy('id')->lockForUpdate()->get(['id', 'estado_actual_id']);
        if ($packages->isEmpty()) {
            throw new BatchHasNoPackages('El lote no tiene paquetes asignados');
        }
        foreach ($packages as $package) {
            if ((int) $package->estado_actual_id !== (int) $states->get('ASIGNADO')) {
                throw new RouteStartConflict('Todos los paquetes del lote deben estar asignados', 'paquetes_no_asignados');
            }
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s');
        $this->connection->table('lotes')->where('id', $batch->id)->update([
            'estado' => BatchStatus::EnRuta->value,
            'started_at' => $timestamp,
        ]);
        $this->connection->table('paquetes')->where('id_lote', $batch->id)->update([
            'estado_actual_id' => (int) $states->get('EN_RUTA'),
            'updated_at' => $timestamp,
        ]);
        $history = [];
        foreach ($packages as $package) {
            $history[] = [
                'id_paquete' => (int) $package->id,
                'estado_anterior_id' => (int) $states->get('ASIGNADO'),
                'estado_nuevo_id' => (int) $states->get('EN_RUTA'),
                'id_usuario_ejecutor' => $command->driver->id,
                'created_at' => $timestamp,
            ];
        }
        $this->connection->table('historial_estados')->insert($history);

        return new StartRouteResult((int) $batch->id, (string) $batch->codigo, $now, $packages->count());
    }
}
