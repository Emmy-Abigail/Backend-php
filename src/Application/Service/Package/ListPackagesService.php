<?php

declare(strict_types=1);

namespace App\Application\Service\Package;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Package\ListPackagesQuery;
use App\Application\Port\In\Package\ListPackagesResult;
use App\Application\Port\In\Package\ListPackagesUseCase;
use App\Application\Port\Out\Persistence\PackageQueryRepository;

final readonly class ListPackagesService implements ListPackagesUseCase
{
    public function __construct(private PackageQueryRepository $repository)
    {
    }

    public function execute(ListPackagesQuery $query): ListPackagesResult
    {
        // La sede siempre sale de la sesión: un operador nunca puede consultar otra sede.
        if ($query->operator->role !== 'OPERADOR' || $query->operator->idSede === null) {
            throw new UserNotAllowed('Solo un operador con sede puede listar paquetes');
        }

        return $this->repository->searchBySede(
            $query->operator->idSede,
            $query->search,
            $query->status,
            $query->from,
            $query->to,
            $query->page,
            $query->perPage,
        );
    }
}
