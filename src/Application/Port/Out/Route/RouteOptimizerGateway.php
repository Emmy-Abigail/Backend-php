<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Route;

interface RouteOptimizerGateway
{
    /**
     * Ordena las paradas de un lote que sale de una sede.
     *
     * @param list<RouteStop> $stops
     * @return list<int> ids de paquete en el orden de entrega
     */
    public function order(int $sedeId, array $stops): array;
}
