<?php

declare(strict_types=1);

namespace App\Application\Service\Batch;

use App\Application\Exception\BatchAccessDenied;
use App\Application\Exception\BatchNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Batch\GetBatchQuery;
use App\Application\Port\In\Batch\GetBatchResult;
use App\Application\Port\In\Batch\GetBatchUseCase;
use App\Application\Port\Out\Batch\BatchReadRepository;
use App\Domain\Batch\BatchStatus;

final readonly class GetBatchService implements GetBatchUseCase
{
    public function __construct(private BatchReadRepository $repository)
    {
    }

    public function execute(GetBatchQuery $query): GetBatchResult
    {
        $viewer = $query->viewer;

        if ($viewer->mustChangePassword) {
            throw new UserNotAllowed('Debes cambiar tu contraseña antes de consultar lotes');
        }

        $isOperator = $viewer->role === 'OPERADOR' && $viewer->idSede !== null;
        if (!$isOperator && $viewer->role !== 'CONDUCTOR') {
            throw new UserNotAllowed('Solo un operador con sede o un conductor puede consultar lotes');
        }

        $summary = $this->repository->findSummary($query->batchId);
        if ($summary === null) {
            throw new BatchNotFound('El lote no existe');
        }

        if ($isOperator) {
            if ($summary->sede['id'] !== $viewer->idSede) {
                throw new BatchAccessDenied('No puede ver lotes de otra sede');
            }

            // Solo un lote en preparación todavía puede recibir conductor.
            $drivers = $summary->status === BatchStatus::EnPreparacion->value
                ? $this->repository->availableDrivers($summary->vehicle['id'])
                : [];

            return new GetBatchResult($summary, $this->repository->packagesOf($summary->id), $drivers, 'OPERADOR');
        }

        if ($summary->driver === null || $summary->driver['id'] !== $viewer->id) {
            throw new BatchAccessDenied('No puede ver lotes de otro conductor');
        }

        return new GetBatchResult($summary, $this->repository->packagesOf($summary->id), [], 'CONDUCTOR');
    }
}
