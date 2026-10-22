<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Pricing;

use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\TariffNotConfigured;
use App\Application\Port\In\Pricing\QuoteShipmentCommand;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Pricing\TariffRepository;
use App\Application\Service\Pricing\QuoteShipmentService;
use App\Domain\Catalog\Department;
use App\Domain\Catalog\District;
use App\Domain\Catalog\Province;
use App\Domain\Catalog\Sede;
use App\Domain\Pricing\InvalidPricingInput;
use App\Domain\Pricing\Tariff;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuoteShipmentServiceTest extends TestCase
{
    private function catalog(): CatalogRepository
    {
        $catalog = $this->createStub(CatalogRepository::class);

        $catalog->method('getSedes')->willReturn([
            new Sede(1, 'Sede Norte', 1, 'NORTE', 'Norte', '150117', 'Los Olivos', 'Av. Carlos Izaguirre', -11.99, -77.07, true),
            new Sede(2, 'Sede Centro', 2, 'CENTRO', 'Centro', '150113', 'Jesús María', 'Av. Brasil', -12.07, -77.04, true),
            new Sede(3, 'Sede Sur', 3, 'SUR', 'Sur', '150140', 'Santiago de Surco', 'Av. Caminos del Inca', -12.14, -77.00, true),
        ]);

        $catalog->method('getGeography')->willReturn([
            new Department('15', 'Lima', [
                new Province('1501', '15', 'Lima', [
                    new District('150117', '1501', 1, 'Los Olivos', 'Los Olivos', -11.99, -77.07, true),
                    new District('150140', '1501', 3, 'Santiago de Surco', 'Santiago de Surco', -12.14, -77.00, true),
                    // Zona 4 (Este) sin sede en este catálogo de prueba.
                    new District('150103', '1501', 4, 'Ate', 'Ate', -12.02, -76.92, true),
                ]),
            ]),
        ]);

        return $catalog;
    }

    private function tariffs(?Tariff $tariff): TariffRepository
    {
        $tariffs = $this->createStub(TariffRepository::class);
        $tariffs->method('findCurrent')->willReturn($tariff);

        return $tariffs;
    }

    private function service(?Tariff $tariff = null): QuoteShipmentService
    {
        return new QuoteShipmentService($this->catalog(), $this->tariffs($tariff ?? Tariff::fromDecimalString(7, '2.50')));
    }

    #[Test]
    public function cotiza_el_caso_de_maria_de_norte_a_sur(): void
    {
        $result = $this->service()->execute(new QuoteShipmentCommand(1, '150140', '25', '12', '30', '20'));

        self::assertSame(9375, $result->quote->amountCents);
        self::assertSame(144, $result->quote->volumetricWeightHundredths);
        self::assertSame(2500, $result->quote->billableWeightHundredths);
        self::assertSame(15, $result->quote->factorTenths);
        self::assertSame(1, $result->originSedeId);
        self::assertSame('Sede Norte', $result->originSedeName);
        self::assertSame('NORTE', $result->originZoneCode);
        self::assertSame('SUR', $result->destinationZoneCode);
    }

    #[Test]
    public function el_origen_sale_de_la_sede_indicada(): void
    {
        // Desde el Centro a cualquier otra zona el factor es 1.2.
        $result = $this->service()->execute(new QuoteShipmentCommand(2, '150140', '25', '12', '30', '20'));

        self::assertSame('CENTRO', $result->originZoneCode);
        self::assertSame(12, $result->quote->factorTenths);
        self::assertSame(7500, $result->quote->amountCents);
    }

    #[Test]
    public function usa_la_tarifa_vigente(): void
    {
        $service = $this->service(Tariff::fromDecimalString(8, '3.00'));

        $result = $service->execute(new QuoteShipmentCommand(1, '150140', '25', '12', '30', '20'));

        self::assertSame(11250, $result->quote->amountCents);
        self::assertSame(300, $result->quote->baseRateCentsPerKg);
    }

    #[Test]
    public function rechaza_una_sede_de_origen_inexistente(): void
    {
        $this->expectException(InvalidCatalogReference::class);

        $this->service()->execute(new QuoteShipmentCommand(99, '150140', '25', '12', '30', '20'));
    }

    #[Test]
    public function rechaza_un_distrito_de_destino_inexistente(): void
    {
        $this->expectException(InvalidCatalogReference::class);
        $this->expectExceptionMessage('distrito');

        $this->service()->execute(new QuoteShipmentCommand(1, '999999', '25', '12', '30', '20'));
    }

    #[Test]
    public function rechaza_un_distrito_cuya_zona_no_tiene_sede(): void
    {
        $this->expectException(InvalidCatalogReference::class);
        $this->expectExceptionMessage('zona');

        $this->service()->execute(new QuoteShipmentCommand(1, '150103', '25', '12', '30', '20'));
    }

    #[Test]
    public function falla_si_no_hay_una_tarifa_vigente(): void
    {
        $service = new QuoteShipmentService($this->catalog(), $this->tariffs(null));

        $this->expectException(TariffNotConfigured::class);

        $service->execute(new QuoteShipmentCommand(1, '150140', '25', '12', '30', '20'));
    }

    #[Test]
    public function rechaza_medidas_invalidas_antes_de_consultar_el_catalogo(): void
    {
        $catalog = $this->createMock(CatalogRepository::class);
        $catalog->expects($this->never())->method('getSedes');
        $catalog->expects($this->never())->method('getGeography');

        $service = new QuoteShipmentService($catalog, $this->tariffs(Tariff::fromDecimalString(7, '2.50')));

        $this->expectException(InvalidPricingInput::class);

        $service->execute(new QuoteShipmentCommand(1, '150140', '51', '12', '30', '20'));
    }
}
