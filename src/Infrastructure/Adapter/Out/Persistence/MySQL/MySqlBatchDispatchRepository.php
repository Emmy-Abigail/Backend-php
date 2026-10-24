<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Exception\BatchAccessDenied;
use App\Application\Exception\BatchAlreadyDispatched;
use App\Application\Exception\BatchHasNoPackages;
use App\Application\Exception\BatchNotFound;
use App\Application\Exception\BatchVersionMismatch;
use App\Application\Exception\BatchWeightExceeded;
use App\Application\Exception\DispatchSnapshotChanged;
use App\Application\Exception\DriverNotAvailable;
use App\Application\Exception\InvalidCatalogReference;
use App\Application\Port\In\Batch\DispatchBatchCommand;
use App\Application\Port\In\Batch\DispatchBatchResult;
use App\Application\Port\Out\Batch\BatchDispatchRepository;
use App\Application\Port\Out\Batch\DispatchSnapshot;
use App\Application\Port\Out\Route\RouteStop;
use App\Domain\Batch\BatchStatus;
use App\Domain\Batch\DriverAvailability;
use App\Domain\Package\DecimalQuantity;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class MySqlBatchDispatchRepository implements BatchDispatchRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function snapshot(int $batchId, int $sedeId): DispatchSnapshot
    {
        $batch = $this->connection->table('lotes')->where('id', $batchId)->first();
        if ($batch === null) {
            throw new BatchNotFound('El lote no existe');
        }
        if ((int) $batch->id_sede !== $sedeId) {
            throw new BatchAccessDenied('No puede despachar lotes de otra sede');
        }
        if ($batch->estado !== BatchStatus::EnPreparacion->value) {
            throw $this->alreadyDispatched($batch);
        }

        $rows = $this->connection->table('paquetes')
            ->where('id_lote', $batchId)
            ->orderBy('fecha_ingreso')
            ->orderBy('id')
            ->get(['id', 'ubigeo_destino']);
        if ($rows->isEmpty()) {
            throw new BatchHasNoPackages('El lote no tiene paquetes');
        }

        $stops = [];
        foreach ($rows as $row) {
            $stops[] = new RouteStop((int) $row->id, (string) $row->ubigeo_destino);
        }

        return new DispatchSnapshot(
            (int) $batch->id,
            (int) $batch->version,
            (int) $batch->id_sede,
            (int) $batch->id_tipo_vehiculo,
            $stops,
        );
    }

    public function dispatch(DispatchBatchCommand $command, DispatchSnapshot $snapshot, array $orderedPackageIds): DispatchBatchResult
    {
        try {
            return $this->connection->transaction(
                fn (): DispatchBatchResult => $this->dispatchLocked($command, $snapshot, $orderedPackageIds),
            );
        } catch (QueryException $exception) {
            // 1205 y 1213: tiempo de espera de bloqueo o interbloqueo. La transacción se revirtió
            // y el servicio vuelve a leer el lote, como hace el registro de paquetes.
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new DispatchSnapshotChanged('El lote esta siendo modificado por otra operacion');
            }

            throw $exception;
        }
    }

    /** @param list<int> $orderedPackageIds */
    private function dispatchLocked(DispatchBatchCommand $command, DispatchSnapshot $snapshot, array $orderedPackageIds): DispatchBatchResult
    {
        $batch = $this->connection->table('lotes')->where('id', $snapshot->batchId)->lockForUpdate()->first();
        if ($batch === null) {
            throw new BatchNotFound('El lote no existe');
        }
        if ((int) $batch->id_sede !== $snapshot->sedeId) {
            throw new BatchAccessDenied('No puede despachar lotes de otra sede');
        }
        if ($batch->estado !== BatchStatus::EnPreparacion->value) {
            throw $this->alreadyDispatched($batch);
        }
        if ((int) $batch->version !== $command->version) {
            throw new BatchVersionMismatch('El lote cambió; recárgalo e inténtalo de nuevo');
        }

        $states = $this->connection->table('estados_paquete')
            ->whereIn('codigo', ['EN_ALMACEN', 'ASIGNADO'])
            ->pluck('id', 'codigo');
        if (!$states->has('EN_ALMACEN') || !$states->has('ASIGNADO')) {
            throw new RuntimeException('Faltan estados de paquete en el catalogo');
        }

        // Con el lote bloqueado, un registro nuevo no puede entrar al lote: si el conjunto
        // de paquetes ya no es el que se envió a Python, el orden calculado no sirve.
        $packages = $this->connection->table('paquetes')
            ->where('id_lote', $batch->id)
            ->lockForUpdate()
            ->get(['id', 'estado_actual_id', 'peso_kg']);
        $currentIds = $packages->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $expectedIds = $orderedPackageIds;
        sort($expectedIds);
        if ($currentIds !== $expectedIds) {
            throw new DispatchSnapshotChanged('Los paquetes del lote cambiaron');
        }
        $totalWeight = 0;
        foreach ($packages as $package) {
            if ((int) $package->estado_actual_id !== (int) $states->get('EN_ALMACEN')) {
                throw new DispatchSnapshotChanged('Un paquete del lote ya no esta en almacen');
            }
            $totalWeight += DecimalQuantity::units($package->peso_kg, 2);
        }

        // Revalidar los pesos actuales, no los del registro ni una suma enviada por el cliente.
        // El bloqueo compartido mantiene la capacidad vigente estable hasta confirmar el despacho.
        $vehicle = $this->connection->table('tipos_vehiculo')->where('id', $batch->id_tipo_vehiculo)
            ->sharedLock()->first(['peso_max_total_kg']);
        if ($vehicle === null) {
            throw new InvalidCatalogReference('El tipo de vehiculo del lote no existe');
        }
        if ($totalWeight > DecimalQuantity::units($vehicle->peso_max_total_kg, 2)) {
            throw new BatchWeightExceeded('El peso total del lote supera la capacidad del vehiculo');
        }

        // El bloqueo del conductor serializa los despachos que compiten por él, de modo que
        // el recuento de lotes pendientes es fiable.
        $driver = $this->connection->table('usuarios')->where('id', $command->driverId)->lockForUpdate()->first();
        if ($driver === null || $driver->rol !== 'CONDUCTOR') {
            throw new InvalidCatalogReference('El conductor indicado no existe');
        }
        if ((int) $driver->id_tipo_vehiculo !== (int) $batch->id_tipo_vehiculo) {
            throw new InvalidCatalogReference('El conductor no maneja el tipo de vehiculo del lote');
        }
        if (!(bool) $driver->activo) {
            throw new DriverNotAvailable('El conductor ya no está disponible');
        }
        $pending = $this->connection->table('lotes')
            ->where('id_conductor', $driver->id)
            ->whereIn('estado', [BatchStatus::Asignado->value, BatchStatus::EnRuta->value])
            ->lockForUpdate()
            ->pluck('id')
            ->count();
        if (!DriverAvailability::canTakeAnotherBatch($pending)) {
            throw new DriverNotAvailable('El conductor ya no está disponible');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s');
        $version = $command->version + 1;

        $this->connection->table('lotes')->where('id', $batch->id)->update([
            'estado' => BatchStatus::Asignado->value,
            'id_conductor' => (int) $driver->id,
            'despachado_por' => $command->operator->id,
            'despachado_at' => $timestamp,
            'version' => $version,
        ]);

        $history = [];
        foreach ($orderedPackageIds as $index => $packageId) {
            $this->connection->table('paquetes')->where('id', $packageId)->update([
                'estado_actual_id' => (int) $states->get('ASIGNADO'),
                'orden_parada' => $index + 1,
                'updated_at' => $timestamp,
            ]);
            $history[] = [
                'id_paquete' => $packageId,
                'estado_anterior_id' => (int) $states->get('EN_ALMACEN'),
                'estado_nuevo_id' => (int) $states->get('ASIGNADO'),
                'id_usuario_ejecutor' => $command->operator->id,
                'created_at' => $timestamp,
            ];
        }
        $this->connection->table('historial_estados')->insert($history);

        return new DispatchBatchResult(
            (int) $batch->id,
            (string) $batch->codigo,
            $version,
            [
                'id' => (int) $driver->id,
                'nombres' => (string) $driver->nombres,
                'placa' => $driver->placa === null ? null : (string) $driver->placa,
            ],
            ['id' => $command->operator->id, 'nombres' => $command->operator->names],
            $now,
            $this->stops($orderedPackageIds),
        );
    }

    /**
     * @param list<int> $orderedPackageIds
     * @return list<array{orden: int, id_paquete: int, tracking_id: string, distrito: array{ubigeo: string, nombre: string}}>
     */
    private function stops(array $orderedPackageIds): array
    {
        $rows = $this->connection->table('paquetes as p')
            ->join('distritos as d', 'd.ubigeo', '=', 'p.ubigeo_destino')
            ->whereIn('p.id', $orderedPackageIds)
            ->orderBy('p.orden_parada')
            ->get(['p.id', 'p.tracking_id', 'p.orden_parada', 'p.ubigeo_destino', 'd.nombre_mostrado']);

        $stops = [];
        foreach ($rows as $row) {
            $stops[] = [
                'orden' => (int) $row->orden_parada,
                'id_paquete' => (int) $row->id,
                'tracking_id' => (string) $row->tracking_id,
                'distrito' => ['ubigeo' => (string) $row->ubigeo_destino, 'nombre' => (string) $row->nombre_mostrado],
            ];
        }

        return $stops;
    }

    private function alreadyDispatched(object $batch): BatchAlreadyDispatched
    {
        if ($batch->despachado_por === null || $batch->id_conductor === null) {
            return new BatchAlreadyDispatched('Este lote ya no esta en preparacion');
        }

        $names = $this->connection->table('usuarios')
            ->whereIn('id', [(int) $batch->despachado_por, (int) $batch->id_conductor])
            ->pluck('nombres', 'id');

        return new BatchAlreadyDispatched(sprintf(
            'Este lote ya fue despachado por %s a %s',
            $names->get((int) $batch->despachado_por, 'otro operador'),
            $names->get((int) $batch->id_conductor, 'un conductor'),
        ));
    }
}
