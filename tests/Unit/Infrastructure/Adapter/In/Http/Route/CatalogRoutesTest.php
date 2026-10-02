<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Port\In\Catalog\GetFailureReasonsUseCase;
use App\Application\Port\In\Catalog\GetGeographyUseCase;
use App\Application\Port\In\Catalog\GetSedesUseCase;
use App\Application\Port\In\Catalog\GetVehicleTypesUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetFailureReasonsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetGeographyAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetSedesAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetVehicleTypesAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CatalogRoutesTest extends TestCase
{
    #[Test]
    public function responde_200_en_todos_los_endpoints_de_catalogo(): void
    {
        $app = AppFactory::create();

        $geoUseCase = $this->createStub(GetGeographyUseCase::class);
        $geoUseCase->method('execute')->willReturn([]);
        $geoAction = new GetGeographyAction($geoUseCase);

        $sedesUseCase = $this->createStub(GetSedesUseCase::class);
        $sedesUseCase->method('execute')->willReturn([]);
        $sedesAction = new GetSedesAction($sedesUseCase);

        $vehUseCase = $this->createStub(GetVehicleTypesUseCase::class);
        $vehUseCase->method('execute')->willReturn([]);
        $vehAction = new GetVehicleTypesAction($vehUseCase);

        $failUseCase = $this->createStub(GetFailureReasonsUseCase::class);
        $failUseCase->method('execute')->willReturn([]);
        $failAction = new GetFailureReasonsAction($failUseCase);

        $registerRoutes = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/CatalogRoutes.php';
        $registerRoutes($app, $geoAction, $sedesAction, $vehAction, $failAction);

        $reqFactory = new ServerRequestFactory();

        self::assertSame(200, $app->handle($reqFactory->createServerRequest('GET', '/api/v1/geography'))->getStatusCode());
        self::assertSame(200, $app->handle($reqFactory->createServerRequest('GET', '/api/v1/sedes'))->getStatusCode());
        self::assertSame(200, $app->handle($reqFactory->createServerRequest('GET', '/api/v1/vehicle-types'))->getStatusCode());
        self::assertSame(200, $app->handle($reqFactory->createServerRequest('GET', '/api/v1/failure-reasons'))->getStatusCode());

        // Alias routes
        self::assertSame(200, $app->handle($reqFactory->createServerRequest('GET', '/geography'))->getStatusCode());
        self::assertSame(200, $app->handle($reqFactory->createServerRequest('GET', '/sedes'))->getStatusCode());
        self::assertSame(200, $app->handle($reqFactory->createServerRequest('GET', '/vehicle-types'))->getStatusCode());
        self::assertSame(200, $app->handle($reqFactory->createServerRequest('GET', '/failure-reasons'))->getStatusCode());
    }
}