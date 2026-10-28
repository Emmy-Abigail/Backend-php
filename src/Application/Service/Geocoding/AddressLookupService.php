<?php

declare(strict_types=1);

namespace App\Application\Service\Geocoding;

use App\Application\Port\In\Geocoding\AddressLookupUseCase;
use App\Application\Port\In\Geocoding\AddressSuggestion;
use App\Application\Port\Out\Geocoding\DistrictDirectory;
use App\Application\Port\Out\Geocoding\GeocodedPlace;
use App\Application\Port\Out\Geocoding\GeocodingGateway;
use App\Domain\Package\Coordinates;

final readonly class AddressLookupService implements AddressLookupUseCase
{
    public const MAX_SUGGESTIONS = 5;

    /** Prefijos del nombre de la calle → el tipo de vía que usa el formulario. */
    private const ROAD_TYPES = [
        'avenida' => 'Av.', 'av' => 'Av.',
        'jiron' => 'Jr.', 'jr' => 'Jr.',
        'calle' => 'Calle', 'cl' => 'Calle',
        'pasaje' => 'Psje.', 'psje' => 'Psje.', 'pje' => 'Psje.',
        'carretera' => 'Carretera', 'carr' => 'Carretera',
    ];

    public function __construct(
        private GeocodingGateway $gateway,
        private DistrictDirectory $districts,
    ) {
    }

    public function suggest(string $query): array
    {
        // Se piden más de las que se muestran porque algunas caen fuera de cobertura.
        $suggestions = $this->translate($this->gateway->search($query, self::MAX_SUGGESTIONS * 3), self::MAX_SUGGESTIONS);

        // El mapa no conoce todos los números de casa: si el operador escribió uno, se conserva.
        if (!preg_match('/\s(\d{1,5}[A-Za-z]?)$/u', $query, $typed)) {
            return $suggestions;
        }

        return array_map(static fn (AddressSuggestion $s): AddressSuggestion => $s->number !== null || $s->roadName === null
            ? $s
            : new AddressSuggestion(
                preg_replace('/^([^,]+)/u', '$1 ' . $typed[1], $s->label, 1) ?? $s->label,
                $s->roadType, $s->roadName, $typed[1], $s->ubigeo, $s->district, $s->lat, $s->lng,
            ), $suggestions);
    }

    public function reverse(float $lat, float $lng): ?AddressSuggestion
    {
        $found = $this->translate($this->gateway->reverse($lat, $lng), 1);
        if ($found === []) {
            return null;
        }

        // Se conserva el punto exacto que marcó el operador, no el de la calle encontrada.
        $address = $found[0];

        return new AddressSuggestion(
            $address->label, $address->roadType, $address->roadName, $address->number,
            $address->ubigeo, $address->district, $lat, $lng,
        );
    }

    /**
     * @param list<GeocodedPlace> $places
     * @return list<AddressSuggestion>
     */
    private function translate(array $places, int $limit): array
    {
        $catalog = $this->catalog();
        $suggestions = [];
        foreach ($places as $place) {
            if (!Coordinates::isInsideLima($place->lat, $place->lng)) {
                continue;
            }
            $district = $this->districtOf($place, $catalog);
            if ($district === null) {
                continue;
            }

            [$roadType, $roadName] = $this->splitRoad($place->street);
            $label = implode(', ', array_filter([
                trim(implode(' ', array_filter([$place->street, $place->houseNumber]))) ?: null,
                $place->street === null ? $place->placeName : null,
                $district['nombre_mostrado'],
            ]));

            $key = $this->normalize($label);
            if (isset($suggestions[$key])) {
                continue;
            }
            $suggestions[$key] = new AddressSuggestion(
                $label, $roadType, $roadName, $place->houseNumber,
                $district['ubigeo'], $district['nombre_mostrado'], $place->lat, $place->lng,
            );
            if (count($suggestions) === $limit) {
                break;
            }
        }

        return array_values($suggestions);
    }

    /** @return array<string, array{ubigeo: string, nombre_oficial: string, nombre_mostrado: string}> */
    private function catalog(): array
    {
        $byName = [];
        foreach ($this->districts->activeDistricts() as $district) {
            $byName[$this->normalize($district['nombre_oficial'])] = $district;
            $byName[$this->normalize($district['nombre_mostrado'])] = $district;
        }

        return $byName;
    }

    /** @param array<string, array{ubigeo: string, nombre_oficial: string, nombre_mostrado: string}> $catalog */
    private function districtOf(GeocodedPlace $place, array $catalog): ?array
    {
        foreach ($place->districtNames as $name) {
            $district = $catalog[$this->normalize($name)] ?? null;
            if ($district !== null) {
                return $district;
            }
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function splitRoad(?string $street): array
    {
        if ($street === null || trim($street) === '') {
            return [null, null];
        }
        $street = trim($street);
        if (preg_match('/^(\S+?)\.?\s+(.+)$/u', $street, $parts)) {
            $type = self::ROAD_TYPES[$this->normalize($parts[1])] ?? null;
            if ($type !== null) {
                return [$type, $parts[2]];
            }
        }

        return [null, $street];
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');

        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
