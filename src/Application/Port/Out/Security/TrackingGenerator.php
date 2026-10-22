<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Security;

interface TrackingGenerator
{
    public function generate(): string;
}
