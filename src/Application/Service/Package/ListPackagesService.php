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
        $viewer = $query->operator;
        if ($viewer->role === 'OPERADOR' && $viewer->idSede !== null) {
            // La sede del operador siempre sale de la sesión: nunca puede consultar otra sede.
            $sedeId = $viewer->idSede;
        } elseif ($viewer->role === 'ADMINISTRADOR') {
            // El administrador supervisa todas las sedes; id_sede es un filtro opcional.
            $sedeId = $query->sedeId;
        } else {
            throw new UserNotAllowed('Solo un operador con sede o un administrador puede listar paquetes');
        }

        return $this->repository->searchBySede(
            $sedeId,
            $query->search,
            $query->status,
            $query->from,
            $query->to,
            $query->page,
            $query->perPage,
        );
    }
}
