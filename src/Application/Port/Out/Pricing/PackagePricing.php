<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Pricing;

use App\Application\Port\Out\Package\RegistrationContext;
use App\Domain\Package\PackageDetails;

interface PackagePricing
{
    public function quote(PackageDetails $details, RegistrationContext $context): PackageQuote;
}
