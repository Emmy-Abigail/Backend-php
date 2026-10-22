<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Pricing;

use App\Domain\Package\DecimalQuantity;
use InvalidArgumentException;

final readonly class PackageQuote
{
    public string $amount;

    public function __construct(public int $tariffId, string $amount)
    {
        $units = DecimalQuantity::units($amount, 2);
        if ($tariffId <= 0 || $units > 99999999) {
            throw new InvalidArgumentException('Cotizacion o tarifa invalida');
        }
        $this->amount = DecimalQuantity::format($units, 2);
    }
}
