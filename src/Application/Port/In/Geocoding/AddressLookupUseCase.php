<?php

declare(strict_types=1);

namespace App\Application\Port\In\Geocoding;

use App\Application\Exception\GeocodingUnavailable;

interface AddressLookupUseCase
{
    /**
     * Direcciones probables mientras el operador escribe; solo distritos con cobertura.
     *
     * @return list<AddressSuggestion>
     * @throws GeocodingUnavailable
     */
    public function suggest(string $query): array;

    /**
     * Dirección del punto marcado en el mapa; null si no hay una en un distrito con cobertura.
     *
     * @throws GeocodingUnavailable
     */
    public function reverse(float $lat, float $lng): ?AddressSuggestion;
}
