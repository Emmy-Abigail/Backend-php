<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Port\Out\Package\RegistrationContext;
use App\Application\Port\Out\Pricing\PackagePricing;
use App\Application\Port\Out\Pricing\PackageQuote;
use App\Domain\Package\PackageDetails;

/** Adaptador exclusivo para pruebas. Nunca conectar esta implementación en public/index.php. */
final readonly class FixedPackagePricing implements PackagePricing
{
    public function __construct(private int $tariffId = 1, private string $amount = '15.50')
    {
    }

    public function quote(PackageDetails $details, RegistrationContext $context): PackageQuote
    {
        return new PackageQuote($this->tariffId, $this->amount);
    }
}
