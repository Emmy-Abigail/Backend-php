<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Geocoding;

use App\Application\Exception\GeocodingUnavailable;
use App\Application\Port\Out\Geocoding\GeocodedPlace;
use App\Application\Port\Out\Geocoding\GeocodingGateway;
use App\Domain\Package\Coordinates;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use Throwable;

/** Photon (OpenStreetMap): gratis y sin clave. Cambiar de proveedor = otro adaptador de este puerto. */
final readonly class PhotonGeocodingGateway implements GeocodingGateway
{
    public function __construct(
        private HttpTransport $transport,
        private string $baseUrl = 'https://photon.komoot.io',
        private float $timeout = 3.0,
    ) {
    }

    public function search(string $query, int $limit): array
    {
        $bbox = sprintf('%s,%s,%s,%s', Coordinates::LIMA_LNG[0], Coordinates::LIMA_LAT[0], Coordinates::LIMA_LNG[1], Coordinates::LIMA_LAT[1]);

        return $this->fetch('/api/?' . http_build_query(['q' => $query, 'limit' => $limit, 'bbox' => $bbox]));
    }

    public function reverse(float $lat, float $lng): array
    {
        return $this->fetch('/reverse?' . http_build_query(['lat' => $lat, 'lon' => $lng, 'limit' => 5]));
    }

    /** @return list<GeocodedPlace> */
    private function fetch(string $path): array
    {
        $parts = parse_url($this->baseUrl);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || !is_finite($this->timeout) || $this->timeout <= 0 || $this->timeout > 10) {
            throw new GeocodingUnavailable('Servicio de direcciones no disponible');
        }

        try {
            $response = $this->transport->get(rtrim($this->baseUrl, '/') . $path, $this->timeout);
        } catch (Throwable) {
            throw new GeocodingUnavailable('Servicio de direcciones no disponible');
        }
        if ($response->status !== 200) {
            throw new GeocodingUnavailable('Servicio de direcciones no disponible');
        }
        try {
            $data = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new GeocodingUnavailable('Respuesta de direcciones invalida');
        }
        $features = is_array($data) ? ($data['features'] ?? null) : null;
        if (!is_array($features)) {
            throw new GeocodingUnavailable('Respuesta de direcciones invalida');
        }

        $places = [];
        foreach ($features as $feature) {
            $place = is_array($feature) ? $this->place($feature) : null;
            if ($place !== null) {
                $places[] = $place;
            }
        }

        return $places;
    }

    /** @param array<string, mixed> $feature */
    private function place(array $feature): ?GeocodedPlace
    {
        $point = $feature['geometry']['coordinates'] ?? null;
        $properties = $feature['properties'] ?? null;
        if (!is_array($point) || !is_array($properties) || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) {
            return null;
        }

        $text = static fn (string $key): ?string => is_string($properties[$key] ?? null) && trim($properties[$key]) !== ''
            ? trim($properties[$key])
            : null;
        // En una calle, Photon pone su nombre en "name"; en una casa o un local, en "street".
        $isStreet = ($properties['type'] ?? null) === 'street';
        $street = $text('street') ?? ($isStreet ? $text('name') : null);

        return new GeocodedPlace(
            $street,
            $text('housenumber'),
            $isStreet ? null : $text('name'),
            array_values(array_filter([$text('district'), $text('city')])),
            (float) $point[1],
            (float) $point[0],
        );
    }
}
