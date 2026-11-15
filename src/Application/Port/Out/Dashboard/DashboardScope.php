<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Dashboard;

/**
 * Alcance de una consulta del dashboard: días completos de Lima (AAAA-MM-DD, ambos incluidos)
 * y, opcionalmente, una sede. La conversión a UTC la hace el adaptador de persistencia.
 */
final readonly class DashboardScope
{
    public function __construct(
        public string $from,
        public string $to,
        public ?int $sedeId = null,
    ) {
    }
}
