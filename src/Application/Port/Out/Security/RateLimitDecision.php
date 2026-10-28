<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Security;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfterSeconds,
    ) {
    }
}
