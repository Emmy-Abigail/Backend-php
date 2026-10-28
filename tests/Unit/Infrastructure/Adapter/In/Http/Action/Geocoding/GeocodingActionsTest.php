<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Geocoding;

use App\Application\Exception\GeocodingUnavailable;
use App\Application\Port\In\Geocoding\AddressLookupUseCase;
use App\Application\Port\In\Geocoding\AddressSuggestion;
use App\Infrastructure\Adapter\In\Http\Action\Geocoding\ReverseGeocodeAction;
use App\Infrastructure\Adapter\In\Http\Action\Geocoding\SuggestAddressesAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class GeocodingActionsTest extends TestCase
{
    private function suggestion(): AddressSuggestion
    {
        return new AddressSuggestion('Jirón Puno 120, Cercado de Lima', 'Jr.', 'Puno', '120', '150101', 'Cercado de Lima', -12.0499923, -77.0337672);
    }

    /** @param array<string, mixed> $query */
    private function call(object $action, array $query): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/geocode')->withQueryParams($query);

        return $action($request, (new ResponseFactory())->createResponse());
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function neverCalled(): AddressLookupUseCase
    {
        $useCase = $this->createMock(AddressLookupUseCase::class);
        $useCase->expects($this->never())->method('suggest');
        $useCase->expects($this->never())->method('reverse');

        return $useCase;
    }

    public function test_suggest_returns_the_list_ready_for_the_form(): void
    {
        $useCase = $this->createMock(AddressLookupUseCase::class);
        $useCase->expects($this->once())->method('suggest')->with('jiron puno 120')->willReturn([$this->suggestion()]);

        $response = $this->call(new SuggestAddressesAction($useCase), ['q' => "  jiron   puno 120 "]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([[
            'etiqueta' => 'Jirón Puno 120, Cercado de Lima', 'tipo_via' => 'Jr.', 'nombre_via' => 'Puno', 'numero' => '120',
            'ubigeo_distrito' => '150101', 'distrito' => 'Cercado de Lima', 'lat' => -12.049992, 'lng' => -77.033767,
        ]], $this->decode($response)['data']);
    }

    public static function invalidQueries(): array
    {
        return [
            'sin q' => [[]],
            'muy corta' => [['q' => 'ab']],
            'solo espacios' => [['q' => '     ']],
            'muy larga' => [['q' => str_repeat('a', 101)]],
            'como lista' => [['q' => ['jiron']]],
        ];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidQueries')]
    public function test_suggest_rejects_bad_queries_without_calling_the_provider(array $query): void
    {
        $response = $this->call(new SuggestAddressesAction($this->neverCalled()), $query);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('busqueda_invalida', $this->decode($response)['error']);
    }

    public function test_provider_down_is_503(): void
    {
        $useCase = $this->createStub(AddressLookupUseCase::class);
        $useCase->method('suggest')->willThrowException(new GeocodingUnavailable('caido'));
        $useCase->method('reverse')->willThrowException(new GeocodingUnavailable('caido'));

        self::assertSame(503, $this->call(new SuggestAddressesAction($useCase), ['q' => 'jiron puno'])->getStatusCode());
        self::assertSame(503, $this->call(new ReverseGeocodeAction($useCase), ['lat' => '-12.05', 'lng' => '-77.03'])->getStatusCode());
    }

    public function test_reverse_returns_the_address_of_the_point(): void
    {
        $useCase = $this->createMock(AddressLookupUseCase::class);
        $useCase->expects($this->once())->method('reverse')->with(-12.0499923, -77.0337672)->willReturn($this->suggestion());

        $response = $this->call(new ReverseGeocodeAction($useCase), ['lat' => '-12.0499923', 'lng' => '-77.0337672']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('150101', $this->decode($response)['data']['ubigeo_distrito']);
    }

    public function test_reverse_without_an_address_is_404(): void
    {
        $useCase = $this->createStub(AddressLookupUseCase::class);
        $useCase->method('reverse')->willReturn(null);

        $response = $this->call(new ReverseGeocodeAction($useCase), ['lat' => '-12.15', 'lng' => '-76.94']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('direccion_no_encontrada', $this->decode($response)['error']);
    }

    public static function invalidPoints(): array
    {
        return [
            'sin lat' => [['lng' => '-77.03']],
            'texto' => [['lat' => 'abc', 'lng' => '-77.03']],
            'notacion cientifica' => [['lat' => '-1.2e1', 'lng' => '-77.03']],
            'fuera de Lima' => [['lat' => '-16.39', 'lng' => '-71.53']],
            'como lista' => [['lat' => ['-12.05'], 'lng' => '-77.03']],
        ];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidPoints')]
    public function test_reverse_rejects_bad_points_without_calling_the_provider(array $query): void
    {
        $response = $this->call(new ReverseGeocodeAction($this->neverCalled()), $query);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('punto_invalido', $this->decode($response)['error']);
    }
}
