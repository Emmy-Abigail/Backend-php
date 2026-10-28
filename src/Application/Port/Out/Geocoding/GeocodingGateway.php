<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Geocoding;

use App\Application\Exception\GeocodingUnavailable;

interface GeocodingGateway
{
    /**
     * @return list<GeocodedPlace>
     * @throws GeocodingUnavailable
     */
    public function search(string $query, int $limit): array;

    /**
     * @return list<GeocodedPlace> lugares cercanos al punto, del más cercano al más lejano
     * @throws GeocodingUnavailable
     */
    public function reverse(float $lat, float $lng): array;
}
