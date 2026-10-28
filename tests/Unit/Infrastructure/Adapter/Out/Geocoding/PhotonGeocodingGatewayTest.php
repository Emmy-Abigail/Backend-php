<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Geocoding;

use App\Application\Exception\GeocodingUnavailable;
use App\Infrastructure\Adapter\Out\Geocoding\PhotonGeocodingGateway;
use App\Infrastructure\Adapter\Out\Http\HttpResponse;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PhotonGeocodingGatewayTest extends TestCase
{
    /** Respuesta real de Photon, recortada. */
    private const BODY = '{"type":"FeatureCollection","features":['
        . '{"geometry":{"type":"Point","coordinates":[-77.0337672,-12.0499923]},"properties":{"type":"house","street":"Jirón Puno","housenumber":"120","district":"Lima","city":"Lima","county":"Provincia de Lima"}},'
        . '{"geometry":{"type":"Point","coordinates":[-77.010549,-12.0236896]},"properties":{"type":"street","name":"Inca Garcilaso de la Vega","city":"San Juan de Lurigancho"}},'
        . '{"geometry":{"type":"Point","coordinates":[-76.9915105,-12.1132967]},"properties":{"type":"house","name":"Centro Comercial Caminos del Inca","district":"Santiago de Surco","city":"Santiago de Surco"}},'
        . '{"geometry":{"type":"Point","coordinates":"mal"},"properties":{}}'
        . ']}';

    public function test_search_sends_the_query_limited_to_lima_and_reads_each_place(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())->method('get')
            ->with($this->callback(static function (string $url): bool {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return str_starts_with($url, 'https://photon.example/api/?')
                    && $query === ['q' => 'jiron puno 120', 'limit' => '15', 'bbox' => '-77.3,-12.6,-76.55,-11.5'];
            }), 3.0)
            ->willReturn(new HttpResponse(200, self::BODY));

        $places = (new PhotonGeocodingGateway($transport, 'https://photon.example/'))->search('jiron puno 120', 15);

        self::assertCount(3, $places);
        self::assertSame(['Jirón Puno', '120', null, ['Lima', 'Lima']], [$places[0]->street, $places[0]->houseNumber, $places[0]->placeName, $places[0]->districtNames]);
        self::assertSame([-12.0499923, -77.0337672], [$places[0]->lat, $places[0]->lng]);
        // En una calle el nombre es la calle; en un local, el nombre del local.
        self::assertSame(['Inca Garcilaso de la Vega', null], [$places[1]->street, $places[1]->placeName]);
        self::assertSame([null, 'Centro Comercial Caminos del Inca'], [$places[2]->street, $places[2]->placeName]);
    }

    public function test_reverse_sends_the_point(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())->method('get')
            ->with('https://photon.example/reverse?lat=-12.1391&lon=-76.9937&limit=5', 3.0)
            ->willReturn(new HttpResponse(200, '{"features":[]}'));

        self::assertSame([], (new PhotonGeocodingGateway($transport, 'https://photon.example'))->reverse(-12.1391, -76.9937));
    }

    public static function failures(): array
    {
        return [
            'error del servidor' => [new HttpResponse(500, 'x')],
            'limite de uso' => [new HttpResponse(429, 'x')],
            'no es json' => [new HttpResponse(200, 'hola')],
            'sin features' => [new HttpResponse(200, '{"type":"FeatureCollection"}')],
        ];
    }

    #[DataProvider('failures')]
    public function test_bad_answers_become_unavailable(HttpResponse $response): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('get')->willReturn($response);

        $this->expectException(GeocodingUnavailable::class);
        (new PhotonGeocodingGateway($transport))->search('jiron puno', 5);
    }

    public function test_network_errors_do_not_leak_details(): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('get')->willThrowException(new RuntimeException('http://interno secreto'));

        try {
            (new PhotonGeocodingGateway($transport))->search('jiron puno', 5);
            self::fail('Se acepto un fallo de red');
        } catch (GeocodingUnavailable $exception) {
            self::assertSame('Servicio de direcciones no disponible', $exception->getMessage());
        }
    }

    public function test_an_invalid_url_makes_no_request(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('get');

        $this->expectException(GeocodingUnavailable::class);
        (new PhotonGeocodingGateway($transport, 'ftp://photon'))->search('jiron puno', 5);
    }
}
