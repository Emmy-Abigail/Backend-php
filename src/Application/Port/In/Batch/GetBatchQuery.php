<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

use App\Application\Port\In\Auth\AuthenticatedUser;

final readonly class GetBatchQuery
{
    public function __construct(
        public AuthenticatedUser $viewer,
        public int $batchId,
    ) {
    }
}
