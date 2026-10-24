<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

final readonly class GetPackageResult
{
    /**
     * @param array<string, mixed> $package
     */
    public function __construct(public array $package)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->package;
    }
}
