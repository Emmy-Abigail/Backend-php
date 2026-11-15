<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Dashboard;

final readonly class OperatorReference
{
    public function __construct(
        public int $id,
        public string $name,
        public string $sedeName,
        public bool $active,
    ) {
    }
}
