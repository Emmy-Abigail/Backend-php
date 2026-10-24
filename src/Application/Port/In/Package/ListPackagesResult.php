<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

final readonly class ListPackagesResult
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'data' => $this->items,
            'meta' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'total_pages' => (int) ceil($this->total / $this->perPage),
            ],
        ];
    }
}
