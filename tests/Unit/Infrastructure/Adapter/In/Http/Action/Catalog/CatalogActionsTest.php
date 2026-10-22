<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Catalog;

use App\Application\Port\In\Catalog\GetFailureReasonsUseCase;
use App\Application\Port\In\Catalog\GetGeographyUseCase;
use App\Application\Port\In\Catalog\GetSedesUseCase;
use App\Application\Port\In\Catalog\GetVehicleTypesUseCase;
use App\Domain\Catalog\Department;
use App\Domain\Catalog\District;
use App\Domain\Catalog\FailureReason;
use App\Domain\Catalog\Province;
use App\Domain\Catalog\Sede;
use App\Domain\Catalog\VehicleType;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetCatalogsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetFailureReasonsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetGeographyAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetSedesAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetVehicleTypesAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CatalogActionsTest extends TestCase
{
    #[Test]
    public function get_geography_action_retorna_json(): void
    {
        $useCase = $this->createStub(GetGeographyUseCase::class);
        $useCase->method('execute')->willReturn([
            new Department('07', 'Callao'),
        ]);

        $response = (new GetGeographyAction($useCase))(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/geography'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('data', $body);
        self::assertSame('07', $body['data'][0]['ubigeo']);
        self::assertSame('Callao', $body['data'][0]['nombre']);
    }

    #[Test]
    public function get_sedes_action_retorna_json(): void
    {
        $useCase = $this->createStub(GetSedesUseCase::class);
        $useCase->method('execute')->willReturn([
            new Sede(1, 'Sede Norte', 1, 'NORTE', 'Norte', '150117', 'Los Olivos', 'Av. Izaguirre', -11.99, -77.07, true),
        ]);

        $response = (new GetSedesAction($useCase))(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/sedes'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Sede Norte', $body['data'][0]['nombre']);
        self::assertSame('NORTE', $body['data'][0]['zona']['codigo']);
    }

    #[Test]
    public function get_vehicle_types_action_retorna_json(): void
    {
        $useCase = $this->createStub(GetVehicleTypesUseCase::class);
        $useCase->method('execute')->willReturn([
            new VehicleType(1, 'MOTORIZADO', 'Motorizado', 1, 20.0, 40.0, 20),
        ]);

        $response = (new GetVehicleTypesAction($useCase))(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/vehicle-types'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('MOTORIZADO', $body['data'][0]['codigo']);
    }

    #[Test]
    public function get_failure_reasons_action_retorna_json(): void
    {
        $useCase = $this->createStub(GetFailureReasonsUseCase::class);
        $useCase->method('execute')->willReturn([
            new FailureReason(1, 'CLIENTE_AUSENTE', 'Cliente ausente'),
        ]);

        $response = (new GetFailureReasonsAction($useCase))(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/failure-reasons'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('CLIENTE_AUSENTE', $body['data'][0]['codigo']);
    }

    #[Test]
    public function get_catalogs_action_junta_distritos_vehiculos_y_motivos_de_fallo(): void
    {
        $geoUseCase = $this->createStub(GetGeographyUseCase::class);
        $geoUseCase->method('execute')->willReturn([
            new Department('15', 'Lima', [
                new Province('1501', '15', 'Lima', [
                    new District('150122', '1501', 3, 'MIRAFLORES', 'Miraflores', -12.12, -77.03, true),
                    new District('150104', '1501', 3, 'BARRANCO', 'Barranco', -12.14, -77.02, true),
                ]),
            ]),
            new Department('07', 'Callao', [
                new Province('0701', '07', 'Callao', [
                    new District('070101', '0701', 5, 'CALLAO', 'Callao', -12.05, -77.12, true),
                ]),
            ]),
        ]);

        $vehUseCase = $this->createStub(GetVehicleTypesUseCase::class);
        $vehUseCase->method('execute')->willReturn([
            new VehicleType(1, 'MOTORIZADO', 'Motorizado', 1, 20.0, 40.0, 20),
        ]);

        $failUseCase = $this->createStub(GetFailureReasonsUseCase::class);
        $failUseCase->method('execute')->willReturn([
            new FailureReason(1, 'CLIENTE_AUSENTE', 'Cliente ausente'),
        ]);

        $response = (new GetCatalogsAction($geoUseCase, $vehUseCase, $failUseCase))(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/catalogs'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true)['data'];
        self::assertSame(['150122', '150104', '070101'], array_column($data['distritos'], 'ubigeo'));
        self::assertSame('Miraflores', $data['distritos'][0]['nombre_mostrado']);
        self::assertSame('MOTORIZADO', $data['vehiculos'][0]['codigo']);
        self::assertSame('CLIENTE_AUSENTE', $data['motivos_fallo'][0]['codigo']);
    }
}
