<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\PriceCalculator;
use App\Domain\Pricing\Quote;
use App\Domain\Pricing\ShipmentMeasures;
use App\Domain\Pricing\Tariff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    private const NORTE = 1;
    private const CENTRO = 2;
    private const SUR = 3;
    private const ESTE = 4;

    private function quote(
        string $weight,
        string $length,
        string $width,
        string $height,
        int $originZone,
        int $destinationZone,
        string $rate = '2.50',
    ): Quote {
        return (new PriceCalculator())->calculate(
            ShipmentMeasures::fromDecimalStrings($weight, $length, $width, $height),
            $originZone,
            $destinationZone,
            Tariff::fromDecimalString(7, $rate),
        );
    }

    #[Test]
    public function caja_densa_se_cobra_por_el_peso_real(): void
    {
        // María: 25 kg, 12 x 30 x 20 cm, Norte -> Sur
        $quote = $this->quote('25', '12', '30', '20', self::NORTE, self::SUR);

        self::assertSame(144, $quote->volumetricWeightHundredths);
        self::assertSame(2500, $quote->billableWeightHundredths);
        self::assertSame(15, $quote->factorTenths);
        self::assertSame(9375, $quote->amountCents);
        self::assertSame(93.75, $quote->amount());
        self::assertSame(1.44, $quote->volumetricWeightKg());
        self::assertSame(25.0, $quote->billableWeightKg());
        self::assertSame(1.5, $quote->factor());
        self::assertSame(2.5, $quote->baseRatePerKg());
    }

    #[Test]
    public function caja_de_tecnopor_se_cobra_por_el_peso_volumetrico(): void
    {
        // 2 kg, 60 x 50 x 40 cm, Centro -> Este
        $quote = $this->quote('2', '60', '50', '40', self::CENTRO, self::ESTE);

        self::assertSame(2400, $quote->volumetricWeightHundredths);
        self::assertSame(2400, $quote->billableWeightHundredths);
        self::assertSame(12, $quote->factorTenths);
        self::assertSame(7200, $quote->amountCents);
    }

    #[Test]
    public function paquete_local_usa_factor_uno(): void
    {
        // 3 kg, 20 x 20 x 20 cm, Norte -> Norte
        $quote = $this->quote('3', '20', '20', '20', self::NORTE, self::NORTE);

        self::assertSame(160, $quote->volumetricWeightHundredths);
        self::assertSame(300, $quote->billableWeightHundredths);
        self::assertSame(10, $quote->factorTenths);
        self::assertSame(750, $quote->amountCents);
    }

    #[Test]
    public function con_peso_real_igual_al_volumetrico_cobra_ese_peso(): void
    {
        // 1.6 kg y 20 x 20 x 20 cm (volumétrico 1.6 kg)
        $quote = $this->quote('1.6', '20', '20', '20', self::NORTE, self::NORTE);

        self::assertSame(160, $quote->billableWeightHundredths);
        self::assertSame(400, $quote->amountCents);
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function redondeos(): array
    {
        return [
            'mitad exacta sube (0.015 -> 0.02)' => ['0.3', '0.05', 2],
            'mitad exacta sube (0.005 -> 0.01)' => ['0.5', '0.01', 1],
            'menos de la mitad baja (0.012 -> 0.01)' => ['0.3', '0.04', 1],
            'más de la mitad sube (0.009 -> 0.01)' => ['0.3', '0.03', 1],
        ];
    }

    #[Test]
    #[DataProvider('redondeos')]
    public function el_monto_se_redondea_half_up_a_dos_decimales(string $weight, string $rate, int $expectedCents): void
    {
        $quote = $this->quote($weight, '10', '10', '10', self::NORTE, self::NORTE, $rate);

        self::assertSame($expectedCents, $quote->amountCents);
    }

    #[Test]
    public function el_monto_se_calcula_con_el_volumetrico_exacto_y_no_con_el_mostrado(): void
    {
        // 33.3 x 10 x 10 cm = 0.666 kg (se muestra 0.67); 0.666 x 2.50 = 1.665 -> 1.67
        $quote = $this->quote('0.3', '33.3', '10', '10', self::NORTE, self::NORTE);

        self::assertSame(67, $quote->volumetricWeightHundredths);
        self::assertSame(67, $quote->billableWeightHundredths);
        self::assertSame(167, $quote->amountCents);
    }

    #[Test]
    public function cambiar_la_tarifa_cambia_el_monto(): void
    {
        $quote = $this->quote('25', '12', '30', '20', self::NORTE, self::SUR, '3.00');

        self::assertSame(11250, $quote->amountCents);
        self::assertSame(300, $quote->baseRateCentsPerKg);
    }

    #[Test]
    public function el_paquete_mas_grande_con_la_tarifa_mas_alta_no_desborda(): void
    {
        // 150 x 150 x 150 cm, 50 kg, tarifa 999999.99, factor 1.5
        $quote = $this->quote('50', '150', '150', '150', self::NORTE, self::SUR, '999999.99');

        // volumétrico: 3 375 000 cm³ / 5000 = 675 kg; 675 x 999999.99 x 1.5 = 1 012 499 989.875 (101 249 998 987.5 céntimos, la mitad sube)
        self::assertSame(67500, $quote->billableWeightHundredths);
        self::assertSame(101249998988, $quote->amountCents);
    }
}
