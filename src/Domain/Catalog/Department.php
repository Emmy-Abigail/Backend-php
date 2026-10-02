<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

final class Department
{
    /**
     * @param list<Province> $provinces
     */
    public function __construct(
        public string $ubigeo,
        public string $name,
        public array $provinces = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ubigeo' => $this->ubigeo,
            'nombre' => $this->name,
            'provincias' => array_map(static fn (Province $p): array => $p->toArray(), $this->provinces),
        ];
    }
}