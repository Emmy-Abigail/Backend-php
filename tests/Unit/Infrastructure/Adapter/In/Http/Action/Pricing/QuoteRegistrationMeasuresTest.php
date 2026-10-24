<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Pricing;

use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Pricing\TariffRepository;
use App\Application\Service\Pricing\QuoteShipmentService;
use App\Domain\Catalog\Department;
use App\Domain\Catalog\District;
use App\Domain\Catalog\Province;
use App\Domain\Catalog\Sede;
use App\Domain\Package\InvalidPackageData;
use App\Domain\Package\PackageDetails;
use App\Domain\Pricing\Tariff;
use App\Infrastructure\Adapter\In\Http\Action\Pricing\QuoteAction;
use App\Infrastructure\Adapter\Out\Pricing\LocalPackagePricing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\PackageFixture;

require_once __DIR__ . '/../../../../../../../Support/PackageFixture.php';

final class QuoteRegistrationMeasuresTest extends TestCase
{
    public static function measurements(): array
    {
        return [
            'peso minimo' => ['peso_kg', 0.1, true],
            'peso maximo' => ['peso_kg', 50, true],
            'peso con ceros no significativos' => ['peso_kg', '50.0000', true],
            'peso con dos decimales' => ['peso_kg', 2.55, true],
            'peso menor sin redondeo' => ['peso_kg', 0.0999999, false],
            'peso mayor sin redondeo' => ['peso_kg', 50.0000001, false],
            'peso precision extra en float' => ['peso_kg', 25.0000001, false],
            'peso precision extra en texto' => ['peso_kg', '25.0000001', false],
            'peso con tres decimales' => ['peso_kg', 2.555, false],
            'largo minimo' => ['largo_cm', 10.0, true],
            'ancho maximo' => ['ancho_cm', 150.0, true],
            'alto con un decimal' => ['alto_cm', 30.1, true],
            'lado con ceros no significativos' => ['largo_cm', '30.1000', true],
            'largo menor sin redondeo' => ['largo_cm', 9.9999999, false],
            'largo mayor sin redondeo' => ['largo_cm', 150.0000001, false],
            'ancho menor sin redondeo' => ['ancho_cm', 9.9999999, false],
            'ancho mayor sin redondeo' => ['ancho_cm', 150.0000001, false],
            'alto menor sin redondeo' => ['alto_cm', 9.9999999, false],
            'alto mayor sin redondeo' => ['alto_cm', 150.0000001, false],
            'lado con precision extra' => ['alto_cm', 30.1000001, false],
            'lado con dos decimales' => ['largo_cm', 30.12, false],
        ];
    }

    #[DataProvider('measurements')]
    public function test_cotizacion_y_registro_aceptan_las_mismas_medidas_sin_redondeo(string $field, mixed $value, bool $valid): void
    {
        $registration = PackageFixture::body();
        $registration[$field] = $value;
        $quoteBody = ['ubigeo_destino' => $registration['direccion']['ubigeo_distrito']]
            + array_intersect_key($registration, array_flip(['peso_kg', 'largo_cm', 'ancho_cm', 'alto_cm']));
        // Simular los valores que recibe el endpoint al decodificar JSON, incluyendo floats.
        $parsed = json_decode(json_encode($quoteBody, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
        $catalog = $this->createStub(CatalogRepository::class);
        $catalog->method('getSedes')->willReturn([
            new Sede(1, 'Sede Norte', 1, 'NORTE', 'Norte', '150117', 'Los Olivos', 'Prueba', -11.99, -77.07, true),
            new Sede(3, 'Sede Sur', 3, 'SUR', 'Sur', '150140', 'Surco', 'Prueba', -12.14, -77.00, true),
        ]);
        $catalog->method('getGeography')->willReturn([
            new Department('15', 'Lima', [new Province('1501', '15', 'Lima', [
                new District('150122', '1501', 3, 'Miraflores', 'Miraflores', -12.12, -77.03, true),
            ])]),
        ]);
        $tariffs = $this->createStub(TariffRepository::class);
        $tariffs->method('findCurrent')->willReturn(Tariff::fromDecimalString(7, '2.50'));
        $action = new QuoteAction(new QuoteShipmentService($catalog, $tariffs));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/quotes')
            ->withAttribute('authUser', new AuthenticatedUser(2, 'Operador', 'operator@example.invalid', 'OPERADOR', false, 1))
            ->withParsedBody($parsed);
        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($valid ? 200 : 422, $response->getStatusCode());
        if ($valid) {
            $details = PackageDetails::fromArray($registration);
            $price = (new LocalPackagePricing($tariffs))->quote($details, PackageFixture::context());
            self::assertSame((float) $price->amount, $body['monto']);
        } else {
            self::assertSame('campo_invalido', $body['error']);
            self::assertStringContainsString($field, $body['message']);
            try {
                PackageDetails::fromArray($registration);
                self::fail('El registro acepto medidas que la cotizacion rechazo');
            } catch (InvalidPackageData $exception) {
                self::assertArrayHasKey($field, $exception->errors);
            }
        }
    }
}
