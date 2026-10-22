<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\InvalidPricingInput;
use App\Domain\Pricing\ShipmentMeasures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShipmentMeasuresTest extends TestCase
{
    #[Test]
    public function guarda_el_peso_en_centesimas_y_los_lados_en_decimas(): void
    {
        $measures = ShipmentMeasures::fromDecimalStrings('12.5', '10.3', '20', '150');

        self::assertSame(1250, $measures->weightHundredths);
        self::assertSame(103, $measures->lengthTenths);
        self::assertSame(200, $measures->widthTenths);
        self::assertSame(1500, $measures->heightTenths);
    }

    #[Test]
    public function acepta_los_limites_exactos(): void
    {
        $minimum = ShipmentMeasures::fromDecimalStrings('0.1', '10', '10', '10');
        $maximum = ShipmentMeasures::fromDecimalStrings('50', '150', '150', '150');

        self::assertSame(10, $minimum->weightHundredths);
        self::assertSame(5000, $maximum->weightHundredths);
        self::assertSame(100, $minimum->lengthTenths);
        self::assertSame(1500, $maximum->heightTenths);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidWeights(): array
    {
        return [
            'menor al mínimo' => ['0.09'],
            'cero' => ['0'],
            'mayor al máximo' => ['50.01'],
            'tres decimales' => ['2.555'],
            'texto' => ['abc'],
            'negativo' => ['-1'],
            'coma decimal' => ['1,5'],
            'vacío' => [''],
            'con espacios' => [' 5'],
            'notación científica' => ['1e1'],
            'demasiados dígitos' => ['1000000'],
        ];
    }

    #[Test]
    #[DataProvider('invalidWeights')]
    public function rechaza_pesos_invalidos(string $weight): void
    {
        $this->expectException(InvalidPricingInput::class);
        $this->expectExceptionMessage('peso_kg');

        ShipmentMeasures::fromDecimalStrings($weight, '10', '10', '10');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSides(): array
    {
        return [
            'menor al mínimo' => ['9.9'],
            'mayor al máximo' => ['150.1'],
            'dos decimales' => ['10.55'],
            'texto' => ['x'],
            'negativo' => ['-10'],
            'vacío' => [''],
        ];
    }

    #[Test]
    #[DataProvider('invalidSides')]
    public function rechaza_largos_invalidos(string $side): void
    {
        $this->expectException(InvalidPricingInput::class);
        $this->expectExceptionMessage('largo_cm');

        ShipmentMeasures::fromDecimalStrings('1', $side, '10', '10');
    }

    #[Test]
    #[DataProvider('invalidSides')]
    public function rechaza_anchos_invalidos(string $side): void
    {
        $this->expectException(InvalidPricingInput::class);
        $this->expectExceptionMessage('ancho_cm');

        ShipmentMeasures::fromDecimalStrings('1', '10', $side, '10');
    }

    #[Test]
    #[DataProvider('invalidSides')]
    public function rechaza_altos_invalidos(string $side): void
    {
        $this->expectException(InvalidPricingInput::class);
        $this->expectExceptionMessage('alto_cm');

        ShipmentMeasures::fromDecimalStrings('1', '10', '10', $side);
    }
}
