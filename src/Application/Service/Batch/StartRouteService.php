<?php

declare(strict_types=1);

namespace App\Application\Service\Batch;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Batch\StartRouteCommand;
use App\Application\Port\In\Batch\StartRouteResult;
use App\Application\Port\In\Batch\StartRouteUseCase;
use App\Application\Port\Out\Batch\BatchRouteRepository;

final readonly class StartRouteService implements StartRouteUseCase
{
    public function __construct(private BatchRouteRepository $repository)
    {
    }

    public function execute(StartRouteCommand $command): StartRouteResult
    {
        if ($command->driver->role !== 'CONDUCTOR' || $command->driver->mustChangePassword) {
            throw new UserNotAllowed('Solo un conductor habilitado puede iniciar una ruta');
        }

        return $this->repository->start($command);
    }
}
