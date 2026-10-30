<?php

declare(strict_types=1);

namespace App\Application\Port\In\Tracking;

final readonly class GetTrackingResult
{
    /** @param array<string, mixed> $tracking Vista pública, sin datos personales. */
    public function __construct(public array $tracking)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->tracking;
    }
}
