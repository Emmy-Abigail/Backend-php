<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Geocoding;

use App\Application\Exception\GeocodingUnavailable;
use App\Application\Port\In\Geocoding\AddressLookupUseCase;
use App\Application\Port\In\Geocoding\AddressSuggestion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/v1/geocode/suggest?q=... — direcciones probables mientras el operador escribe. */
final readonly class SuggestAddressesAction
{
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 100;

    public function __construct(private AddressLookupUseCase $useCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams()['q'] ?? null;
        $query = is_string($query) ? trim(preg_replace('/\s+/u', ' ', $query) ?? '') : null;
        if ($query === null || mb_strlen($query) < self::MIN_LENGTH || mb_strlen($query) > self::MAX_LENGTH) {
            return GeocodingJson::write($response, 422, [
                'error' => 'busqueda_invalida',
                'message' => sprintf('Escriba entre %d y %d caracteres', self::MIN_LENGTH, self::MAX_LENGTH),
            ]);
        }

        try {
            $suggestions = $this->useCase->suggest($query);
        } catch (GeocodingUnavailable) {
            return GeocodingJson::unavailable($response);
        }

        return GeocodingJson::write($response, 200, [
            'data' => array_map(static fn (AddressSuggestion $suggestion): array => $suggestion->toArray(), $suggestions),
        ]);
    }
}
