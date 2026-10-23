<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

use App\Application\Port\In\Auth\AuthenticatedUser;

final readonly class DispatchBatchCommand
{
    public function __construct(
        public AuthenticatedUser $operator,
        public int $batchId,
        public int $driverId,
        public int $version,
    ) {
    }
}
