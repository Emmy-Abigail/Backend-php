<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Geocoding;

use App\Application\Exception\GeocodingUnavailable;
use App\Application\Port\In\Geocoding\AddressLookupUseCase;
use App\Domain\Package\Coordinates;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/v1/geocode/reverse?lat=...&lng=... — llena el formulario con el punto marcado en el mapa. */
final readonly class ReverseGeocodeAction
{
    public function __construct(private AddressLookupUseCase $useCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $lat = $this->number($params['lat'] ?? null);
        $lng = $this->number($params['lng'] ?? null);
        if ($lat === null || $lng === null) {
            return GeocodingJson::write($response, 422, ['error' => 'punto_invalido', 'message' => 'Envie lat y lng como numeros']);
        }
        if (!Coordinates::isInsideLima($lat, $lng)) {
            return GeocodingJson::write($response, 422, [
                'error' => 'punto_invalido',
                'message' => 'El punto debe estar dentro de Lima Metropolitana o Callao',
            ]);
        }

        try {
            $address = $this->useCase->reverse($lat, $lng);
        } catch (GeocodingUnavailable) {
            return GeocodingJson::unavailable($response);
        }
        if ($address === null) {
            return GeocodingJson::write($response, 404, [
                'error' => 'direccion_no_encontrada',
                'message' => 'No se encontro una direccion en un distrito con cobertura; complete los campos a mano',
            ]);
        }

        return GeocodingJson::write($response, 200, ['data' => $address->toArray()]);
    }

    private function number(mixed $value): ?float
    {
        if (!is_string($value) || !preg_match('/^-?[0-9]{1,3}(\.[0-9]{1,12})?$/D', trim($value))) {
            return null;
        }

        return (float) $value;
    }
}
