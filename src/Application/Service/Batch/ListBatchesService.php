<?php

declare(strict_types=1);

namespace App\Application\Service\Batch;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Batch\BatchPage;
use App\Application\Port\In\Batch\ListBatchesQuery;
use App\Application\Port\In\Batch\ListBatchesUseCase;
use App\Application\Port\Out\Batch\BatchReadRepository;
use App\Domain\Batch\BatchStatus;

final readonly class ListBatchesService implements ListBatchesUseCase
{
    public function __construct(private BatchReadRepository $repository)
    {
    }

    public function execute(ListBatchesQuery $query): BatchPage
    {
        $viewer = $query->viewer;

        if ($viewer->mustChangePassword) {
            throw new UserNotAllowed('Debes cambiar tu contraseña antes de consultar lotes');
        }

        // El Operador ve los lotes de su sede; el Conductor, solo los suyos.
        if ($viewer->role === 'OPERADOR' && $viewer->idSede !== null) {
            $statuses = $query->status === null ? BatchStatus::cases() : [$query->status];

            return $this->repository->search($viewer->idSede, null, $statuses, $query->page, $query->size);
        }

        if ($viewer->role === 'CONDUCTOR') {
            $statuses = $query->status === null
                ? [BatchStatus::Asignado, BatchStatus::EnRuta]
                : [$query->status];

            return $this->repository->search(null, $viewer->id, $statuses, $query->page, $query->size);
        }

        throw new UserNotAllowed('Solo un operador con sede o un conductor puede consultar lotes');
    }
}
