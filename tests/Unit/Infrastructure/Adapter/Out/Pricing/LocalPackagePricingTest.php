<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Pricing;

use App\Application\Exception\PricingUnavailable;
use App\Application\Port\Out\Pricing\TariffRepository;
use App\Domain\Package\PackageDetails;
use App\Domain\Pricing\Tariff;
use App\Infrastructure\Adapter\Out\Pricing\LocalPackagePricing;
use PHPUnit\Framework\TestCase;
use Tests\Support\PackageFixture;

require_once __DIR__ . '/../../../../../Support/PackageFixture.php';

final class LocalPackagePricingTest extends TestCase
{
    public function test_uses_real_weight_zone_factor_and_rounding_with_the_same_tariff_id(): void
    {
        $tariffs = $this->createMock(TariffRepository::class);
        $tariffs->expects($this->once())->method('findCurrent')->willReturn(Tariff::fromDecimalString(7, '0.05'));
        $quote = (new LocalPackagePricing($tariffs))->quote(PackageFixture::command()->details, PackageFixture::context());

        self::assertSame(7, $quote->tariffId);
        self::assertSame('0.23', $quote->amount);
    }

    public function test_uses_volumetric_weight_when_it_is_greater_than_real_weight(): void
    {
        $tariffs = $this->createStub(TariffRepository::class);
        $tariffs->method('findCurrent')->willReturn(Tariff::fromDecimalString(1, '0.05'));
        $body = PackageFixture::body();
        $body['largo_cm'] = $body['ancho_cm'] = $body['alto_cm'] = 100;
        $quote = (new LocalPackagePricing($tariffs))->quote(PackageDetails::fromArray($body), PackageFixture::context());

        self::assertSame('15.00', $quote->amount);
    }

    public function test_missing_tariff_is_unavailable_instead_of_returning_a_default_price(): void
    {
        $tariffs = $this->createStub(TariffRepository::class);
        $tariffs->method('findCurrent')->willReturn(null);
        $this->expectException(PricingUnavailable::class);
        (new LocalPackagePricing($tariffs))->quote(PackageFixture::command()->details, PackageFixture::context());
    }

    public function test_price_exceeding_database_capacity_is_rejected_before_persistence(): void
    {
        $tariffs = $this->createStub(TariffRepository::class);
        $tariffs->method('findCurrent')->willReturn(Tariff::fromDecimalString(1, '999999.99'));
        $body = PackageFixture::body();
        $body['largo_cm'] = $body['ancho_cm'] = $body['alto_cm'] = 150;
        $this->expectException(PricingUnavailable::class);
        (new LocalPackagePricing($tariffs))->quote(PackageDetails::fromArray($body), PackageFixture::context());
    }
}
