<?php

declare(strict_types=1);

namespace App\Application\Port\In\Dashboard;

final readonly class DashboardMetricsResult
{
    /** @param array<string, mixed> $metrics */
    public function __construct(public array $metrics)
    {
    }

    /** @return array{data: array<string, mixed>} */
    public function toArray(): array
    {
        return ['data' => $this->metrics];
    }
}
