<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Domain\Batch\BatchStatus;

final readonly class ListBatchesQuery
{
    public function __construct(
        public AuthenticatedUser $viewer,
        public ?BatchStatus $status,
        public int $page,
        public int $size,
    ) {
    }
}
