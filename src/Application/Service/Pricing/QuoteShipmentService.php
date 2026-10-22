<?php

declare(strict_types=1);

namespace App\Application\Service\Pricing;

use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\TariffNotConfigured;
use App\Application\Port\In\Pricing\QuoteShipmentCommand;
use App\Application\Port\In\Pricing\QuoteShipmentResult;
use App\Application\Port\In\Pricing\QuoteShipmentUseCase;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Pricing\TariffRepository;
use App\Domain\Catalog\District;
use App\Domain\Catalog\Sede;
use App\Domain\Pricing\PriceCalculator;
use App\Domain\Pricing\ShipmentMeasures;

final readonly class QuoteShipmentService implements QuoteShipmentUseCase
{
    public function __construct(
        private CatalogRepository $catalogRepository,
        private TariffRepository $tariffRepository,
        private PriceCalculator $priceCalculator = new PriceCalculator(),
    ) {
    }

    public function execute(QuoteShipmentCommand $command): QuoteShipmentResult
    {
        $measures = ShipmentMeasures::fromDecimalStrings(
            $command->weightKg,
            $command->lengthCm,
            $command->widthCm,
            $command->heightCm,
        );

        $sedes = $this->catalogRepository->getSedes();

        $originSede = $this->findSede($sedes, $command->originSedeId);
        if ($originSede === null) {
            throw new InvalidCatalogReference('La sede de origen no existe');
        }

        $destination = $this->findDistrict($command->destinationUbigeo);
        if ($destination === null) {
            throw new InvalidCatalogReference('El distrito de destino no existe o no está activo');
        }

        // Hay una sola sede por zona, así que de ahí sale el código de la zona de destino.
        $destinationZoneCode = $this->zoneCode($sedes, $destination->zoneId);
        if ($destinationZoneCode === null) {
            throw new InvalidCatalogReference('La zona del distrito de destino no existe');
        }

        $tariff = $this->tariffRepository->findCurrent();
        if ($tariff === null) {
            throw new TariffNotConfigured('No hay una tarifa base vigente');
        }

        $quote = $this->priceCalculator->calculate(
            $measures,
            $originSede->idZona,
            $destination->zoneId,
            $tariff,
        );

        return new QuoteShipmentResult(
            $quote,
            $originSede->id,
            $originSede->nombre,
            $originSede->zonaCodigo,
            $destinationZoneCode,
        );
    }

    /**
     * @param list<Sede> $sedes
     */
    private function findSede(array $sedes, int $sedeId): ?Sede
    {
        foreach ($sedes as $sede) {
            if ($sede->id === $sedeId) {
                return $sede;
            }
        }

        return null;
    }

    private function findDistrict(string $ubigeo): ?District
    {
        foreach ($this->catalogRepository->getGeography() as $department) {
            foreach ($department->provinces as $province) {
                foreach ($province->districts as $district) {
                    if ($district->ubigeo === $ubigeo) {
                        return $district;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<Sede> $sedes
     */
    private function zoneCode(array $sedes, int $zoneId): ?string
    {
        foreach ($sedes as $sede) {
            if ($sede->idZona === $zoneId) {
                return $sede->zonaCodigo;
            }
        }

        return null;
    }
}
