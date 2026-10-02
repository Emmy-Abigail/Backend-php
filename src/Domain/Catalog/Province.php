<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

final class Province
{
    /**
     * @param list<District> $districts
     */
    public function __construct(
        public string $ubigeo,
        public string $departmentUbigeo,
        public string $name,
        public array $districts = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ubigeo' => $this->ubigeo,
            'ubigeo_departamento' => $this->departmentUbigeo,
            'nombre' => $this->name,
            'distritos' => array_map(static fn (District $d): array => $d->toArray(), $this->districts),
        ];
    }
}