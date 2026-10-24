<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

final readonly class BatchPage
{
    /** @param list<BatchSummary> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $size,
        public int $total,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (BatchSummary $batch): array => $batch->toArray(), $this->items),
            'meta' => [
                'page' => $this->page,
                'size' => $this->size,
                'total' => $this->total,
                'total_pages' => (int) ceil($this->total / $this->size),
            ],
        ];
    }
}
