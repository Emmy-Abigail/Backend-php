<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Geocoding;

use App\Application\Exception\GeocodingUnavailable;
use App\Application\Port\Out\Geocoding\DistrictDirectory;
use App\Application\Port\Out\Geocoding\GeocodedPlace;
use App\Application\Port\Out\Geocoding\GeocodingGateway;
use App\Application\Service\Geocoding\AddressLookupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressLookupServiceTest extends TestCase
{
    private function directory(): DistrictDirectory
    {
        $directory = $this->createStub(DistrictDirectory::class);
        $directory->method('activeDistricts')->willReturn([
            ['ubigeo' => '150101', 'nombre_oficial' => 'Lima', 'nombre_mostrado' => 'Cercado de Lima'],
            ['ubigeo' => '150111', 'nombre_oficial' => 'El Agustino', 'nombre_mostrado' => 'El Agustino'],
            ['ubigeo' => '150132', 'nombre_oficial' => 'San Juan de Lurigancho', 'nombre_mostrado' => 'San Juan de Lurigancho'],
            ['ubigeo' => '150140', 'nombre_oficial' => 'Santiago de Surco', 'nombre_mostrado' => 'Santiago de Surco'],
            ['ubigeo' => '070106', 'nombre_oficial' => 'Ventanilla', 'nombre_mostrado' => 'Ventanilla'],
        ]);

        return $directory;
    }

    /** @param list<GeocodedPlace> $places */
    private function service(array $places): AddressLookupService
    {
        $gateway = $this->createStub(GeocodingGateway::class);
        $gateway->method('search')->willReturn($places);
        $gateway->method('reverse')->willReturn($places);

        return new AddressLookupService($gateway, $this->directory());
    }

    public function test_translates_a_house_number_into_the_form_fields(): void
    {
        $suggestions = $this->service([
            new GeocodedPlace('Jirón Puno', '120', null, ['Lima', 'Lima'], -12.04999, -77.03377),
        ])->suggest('jiron puno 120');

        self::assertSame([[
            'etiqueta' => 'Jirón Puno 120, Cercado de Lima',
            'tipo_via' => 'Jr.',
            'nombre_via' => 'Puno',
            'numero' => '120',
            'ubigeo_distrito' => '150101',
            'distrito' => 'Cercado de Lima',
            'lat' => -12.04999,
            'lng' => -77.03377,
        ]], array_map(static fn ($s): array => $s->toArray(), $suggestions));
    }

    public function test_uses_the_city_when_the_district_is_missing_and_ignores_accents(): void
    {
        $suggestions = $this->service([
            new GeocodedPlace('Inca Garcilaso de la Vega', null, null, ['San Juan de Lurigáncho'], -12.0237, -77.0105),
        ])->suggest('inca garcilaso');

        self::assertSame('150132', $suggestions[0]->ubigeo);
        self::assertNull($suggestions[0]->roadType);
        self::assertSame('Inca Garcilaso de la Vega', $suggestions[0]->roadName);
        self::assertNull($suggestions[0]->number);
    }

    public static function roadTypes(): array
    {
        return [
            ['Avenida Caminos del Inca', 'Av.', 'Caminos del Inca'],
            ['Av. Caminos del Inca', 'Av.', 'Caminos del Inca'],
            ['Jirón Puno', 'Jr.', 'Puno'],
            ['Calle Los Pinos', 'Calle', 'Los Pinos'],
            ['Pasaje Olaya', 'Psje.', 'Olaya'],
            ['Carretera Central', 'Carretera', 'Central'],
            ['Malecón Cisneros', null, 'Malecón Cisneros'],
        ];
    }

    #[DataProvider('roadTypes')]
    public function test_splits_the_road_type_used_by_the_form(string $street, ?string $type, string $name): void
    {
        $suggestion = $this->service([new GeocodedPlace($street, null, null, ['Santiago de Surco'], -12.14, -76.99)])->suggest('x x x')[0];

        self::assertSame($type, $suggestion->roadType);
        self::assertSame($name, $suggestion->roadName);
    }

    public function test_drops_districts_without_coverage_points_outside_lima_and_duplicates(): void
    {
        $suggestions = $this->service([
            new GeocodedPlace('Inca Garcilaso de la Vega', null, null, ['Villa María del Triunfo'], -12.15, -76.94),
            new GeocodedPlace('Inca Garcilaso de la Vega', null, null, ['El Agustino'], -12.0538, -77.0059),
            new GeocodedPlace('Inca Garcilaso de la Vega', null, null, ['El Agustino'], -12.0540, -77.0060),
            new GeocodedPlace('Calle Mercaderes', null, null, ['Lima'], -16.39, -71.53),
        ])->suggest('inca garcilaso');

        self::assertSame(['Inca Garcilaso de la Vega, El Agustino'], array_map(static fn ($s): string => $s->label, $suggestions));
    }

    public function test_returns_at_most_five_suggestions(): void
    {
        $places = [];
        for ($number = 1; $number <= 8; $number++) {
            $places[] = new GeocodedPlace('Jirón Puno', (string) $number, null, ['Lima'], -12.05, -77.03);
        }

        self::assertCount(5, $this->service($places)->suggest('jiron puno'));
    }

    public function test_keeps_the_house_number_the_operator_typed_when_the_map_does_not_know_it(): void
    {
        $suggestions = $this->service([
            new GeocodedPlace('Avenida Caminos del Inca', null, null, ['Santiago de Surco'], -12.13, -76.98),
            new GeocodedPlace('Jirón Puno', '120', null, ['Lima'], -12.05, -77.03),
            new GeocodedPlace(null, null, 'Centro Comercial Caminos del Inca', ['Santiago de Surco'], -12.11, -76.99),
        ])->suggest('av caminos del inca 2500');

        self::assertSame('Avenida Caminos del Inca 2500, Santiago de Surco', $suggestions[0]->label);
        self::assertSame('2500', $suggestions[0]->number);
        // Si el mapa ya trae su número, o el lugar no es una calle, no se toca.
        self::assertSame('120', $suggestions[1]->number);
        self::assertNull($suggestions[2]->number);
    }

    public function test_a_shop_without_street_is_labelled_with_its_name(): void
    {
        $suggestion = $this->service([
            new GeocodedPlace(null, null, 'Centro Comercial Caminos del Inca', ['Santiago de Surco'], -12.11, -76.99),
        ])->suggest('caminos del inca')[0];

        self::assertSame('Centro Comercial Caminos del Inca, Santiago de Surco', $suggestion->label);
        self::assertNull($suggestion->roadName);
    }

    public function test_reverse_keeps_the_exact_point_marked_on_the_map(): void
    {
        $address = $this->service([
            new GeocodedPlace('Avenida Surco', null, null, ['Santiago de Surco'], -12.13925, -76.99382),
        ])->reverse(-12.1391, -76.9937);

        self::assertSame('Avenida Surco, Santiago de Surco', $address?->label);
        self::assertSame('Av.', $address->roadType);
        self::assertSame('150140', $address->ubigeo);
        self::assertSame(-12.1391, $address->lat);
        self::assertSame(-76.9937, $address->lng);
    }

    public function test_reverse_without_coverage_returns_null(): void
    {
        self::assertNull($this->service([
            new GeocodedPlace('Avenida Lima', null, null, ['Villa María del Triunfo'], -12.15, -76.94),
        ])->reverse(-12.15, -76.94));
    }

    public function test_provider_failures_reach_the_caller(): void
    {
        $gateway = $this->createStub(GeocodingGateway::class);
        $gateway->method('search')->willThrowException(new GeocodingUnavailable('caido'));

        $this->expectException(GeocodingUnavailable::class);
        (new AddressLookupService($gateway, $this->directory()))->suggest('jiron puno');
    }
}
