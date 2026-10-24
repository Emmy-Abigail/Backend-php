<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Delivery;

final readonly class PackageEvidenceReference
{
    public function __construct(public int $sedeId, public string $status, public ?string $key)
    {
    }
}
