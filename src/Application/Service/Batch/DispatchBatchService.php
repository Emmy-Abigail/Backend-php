<?php

declare(strict_types=1);

namespace App\Application\Service\Batch;

use App\Application\Exception\BatchVersionMismatch;
use App\Application\Exception\DispatchSnapshotChanged;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Batch\DispatchBatchCommand;
use App\Application\Port\In\Batch\DispatchBatchResult;
use App\Application\Port\In\Batch\DispatchBatchUseCase;
use App\Application\Port\Out\Batch\BatchDispatchRepository;
use App\Application\Port\Out\Route\RouteOptimizerGateway;

final readonly class DispatchBatchService implements DispatchBatchUseCase
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private BatchDispatchRepository $repository,
        private RouteOptimizerGateway $routes,
    ) {
    }

    public function execute(DispatchBatchCommand $command): DispatchBatchResult
    {
        $operator = $command->operator;

        if ($operator->role !== 'OPERADOR' || $operator->idSede === null || $operator->mustChangePassword) {
            throw new UserNotAllowed('Solo un operador habilitado con sede puede despachar lotes');
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $snapshot = $this->repository->snapshot($command->batchId, $operator->idSede);

            if ($snapshot->version !== $command->version) {
                throw new BatchVersionMismatch('El lote cambió; recárgalo e inténtalo de nuevo');
            }

            // Python se consulta antes de la transacción: una llamada lenta no debe
            // mantener bloqueados el lote, sus paquetes ni el conductor.
            $order = $this->routes->order($snapshot->sedeId, $snapshot->stops);

            try {
                return $this->repository->dispatch($command, $snapshot, $order);
            } catch (DispatchSnapshotChanged) {
                // Entró un paquete o hubo un bloqueo mientras se calculaba la ruta:
                // se vuelve a leer el lote para que el orden incluya todos sus paquetes.
            }
        }

        throw new DispatchSnapshotChanged('El lote cambió durante el despacho; inténtalo de nuevo');
    }
}
