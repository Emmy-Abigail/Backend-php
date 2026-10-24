<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Batch;

use App\Application\Port\In\Batch\StartRouteCommand;
use App\Application\Port\In\Batch\StartRouteResult;

interface BatchRouteRepository
{
    /** Revalida y guarda lote, paquetes e historial de forma atómica, serializando por conductor. */
    public function start(StartRouteCommand $command): StartRouteResult;
}
