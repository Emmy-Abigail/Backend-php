<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Pricing;

use App\Application\Exception\PricingUnavailable;
use App\Application\Port\Out\Package\RegistrationContext;
use App\Application\Port\Out\Pricing\PackagePricing;
use App\Application\Port\Out\Pricing\PackageQuote;
use App\Application\Port\Out\Pricing\TariffRepository;
use App\Domain\Package\DecimalQuantity;
use App\Domain\Package\PackageDetails;
use App\Domain\Pricing\InvalidPricingInput;
use App\Domain\Pricing\PriceCalculator;
use App\Domain\Pricing\ShipmentMeasures;
use InvalidArgumentException;

/** Reutiliza el cálculo de /quotes sin llamadas HTTP al propio backend. */
final readonly class LocalPackagePricing implements PackagePricing
{
    public function __construct(
        private TariffRepository $tariffs,
        private PriceCalculator $calculator = new PriceCalculator(),
    ) {
    }

    public function quote(PackageDetails $details, RegistrationContext $context): PackageQuote
    {
        try {
            $tariff = $this->tariffs->findCurrent();
            if ($tariff === null) {
                throw new PricingUnavailable('No hay una tarifa vigente para registrar el paquete');
            }
            $measures = $details->measurements();
            $quote = $this->calculator->calculate(
                ShipmentMeasures::fromDecimalStrings(
                    $measures['peso_kg'], $measures['largo_cm'],
                    $measures['ancho_cm'], $measures['alto_cm'],
                ),
                $context->originZoneId,
                $context->destinationZoneId,
                $tariff,
            );

            // El importe y el ID provienen de la misma tarifa, consultada una sola vez.
            return new PackageQuote($tariff->id, DecimalQuantity::format($quote->amountCents, 2));
        } catch (InvalidPricingInput | InvalidArgumentException $exception) {
            // No sustituir una tarifa inválida o un monto fuera del límite de la BD por precios ficticios.
            throw new PricingUnavailable('No se pudo obtener una cotizacion valida', previous: $exception);
        }
    }
}
