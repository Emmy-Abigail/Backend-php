<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

final class FailureReason
{
    public function __construct(
        public int $id,
        public string $codigo,
        public string $nombre,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
        ];
    }
}