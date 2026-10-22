<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\InvalidPricingInput;
use App\Domain\Pricing\Tariff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TariffTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function validRates(): array
    {
        return [
            'dos decimales' => ['2.50', 250],
            'un decimal' => ['2.5', 250],
            'entero' => ['3', 300],
            'valor semilla' => ['0.05', 5],
            'máximo de la columna' => ['999999.99', 99999999],
        ];
    }

    #[Test]
    #[DataProvider('validRates')]
    public function convierte_la_tarifa_a_centimos(string $rate, int $expectedCents): void
    {
        $tariff = Tariff::fromDecimalString(7, $rate);

        self::assertSame(7, $tariff->id);
        self::assertSame($expectedCents, $tariff->baseRateCentsPerKg);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRates(): array
    {
        return [
            'cero' => ['0'],
            'cero con decimales' => ['0.00'],
            'tres decimales' => ['1.234'],
            'negativa' => ['-1'],
            'texto' => ['abc'],
            'vacía' => [''],
        ];
    }

    #[Test]
    #[DataProvider('invalidRates')]
    public function rechaza_tarifas_invalidas(string $rate): void
    {
        $this->expectException(InvalidPricingInput::class);

        Tariff::fromDecimalString(1, $rate);
    }

    #[Test]
    public function rechaza_una_tarifa_no_positiva_al_construirla(): void
    {
        $this->expectException(InvalidPricingInput::class);

        new Tariff(1, 0);
    }
}
