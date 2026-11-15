<?php

declare(strict_types=1);

namespace App\Application\Port\In\Dashboard;

use App\Application\Port\In\Auth\AuthenticatedUser;

final readonly class GetDashboardMetricsQuery
{
    /**
     * @param string $from días de Lima AAAA-MM-DD, ambos incluidos
     * @param ?int $sedeId solo lo usa el ADMINISTRADOR; el OPERADOR siempre ve su propia sede
     */
    public function __construct(
        public AuthenticatedUser $user,
        public string $from,
        public string $to,
        public ?int $sedeId = null,
    ) {
    }
}
