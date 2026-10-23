<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Batch;

use App\Application\Port\In\Batch\DispatchBatchCommand;
use App\Application\Port\In\Batch\DispatchBatchResult;

interface BatchDispatchRepository
{
    /**
     * Lee el lote sin bloquearlo.
     *
     * @throws \App\Application\Exception\BatchNotFound
     * @throws \App\Application\Exception\BatchAccessDenied
     * @throws \App\Application\Exception\BatchAlreadyDispatched
     * @throws \App\Application\Exception\BatchHasNoPackages
     */
    public function snapshot(int $batchId, int $sedeId): DispatchSnapshot;

    /**
     * Despacha el lote en una sola transacción: bloquea el lote, sus paquetes y el
     * conductor, revalida todo y guarda conductor, paradas, estados e historial.
     *
     * @param list<int> $orderedPackageIds ids de paquete en el orden de entrega
     *
     * @throws \App\Application\Exception\DispatchSnapshotChanged si el lote cambió desde la lectura
     * @throws \App\Application\Exception\BatchVersionMismatch
     * @throws \App\Application\Exception\BatchAlreadyDispatched
     * @throws \App\Application\Exception\DriverNotAvailable
     * @throws \App\Application\Exception\InvalidCatalogReference
     */
    public function dispatch(DispatchBatchCommand $command, DispatchSnapshot $snapshot, array $orderedPackageIds): DispatchBatchResult;
}
