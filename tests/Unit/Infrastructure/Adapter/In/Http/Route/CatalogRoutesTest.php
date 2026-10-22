<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Exception\InvalidToken;
use App\Application\Exception\TokenExpired;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Catalog\GetFailureReasonsUseCase;
use App\Application\Port\In\Catalog\GetGeographyUseCase;
use App\Application\Port\In\Catalog\GetSedesUseCase;
use App\Application\Port\In\Catalog\GetVehicleTypesUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetCatalogsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetFailureReasonsAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetGeographyAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetSedesAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetVehicleTypesAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CatalogRoutesTest extends TestCase
{
    #[Test]
    #[DataProvider('rutas')]
    public function rechaza_sin_token_en_rutas_versionadas_y_alias(string $path): void
    {
        $authenticate = $this->createMock(AuthenticateTokenUseCase::class);
        $authenticate->expects($this->never())->method('execute');
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $response = $this->app($authenticate, true)->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['message' => 'Token no proporcionado'], json_decode((string) $response->getBody(), true));
    }

    #[Test]
    #[DataProvider('tokensRechazados')]
    public function rechaza_token_invalido_expirado_o_usuario_inactivo(string $path, string $exception, string $message): void
    {
        $authenticate = $this->createMock(AuthenticateTokenUseCase::class);
        $authenticate->expects($this->once())->method('execute')->with('token-rechazado')->willThrowException(new $exception());
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withHeader('Authorization', 'Bearer token-rechazado');
        $response = $this->app($authenticate, true)->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['message' => $message], json_decode((string) $response->getBody(), true));
    }

    #[Test]
    #[DataProvider('usuariosAutenticados')]
    public function permite_consultar_con_token_valido_sin_restringir_el_rol(string $path, string $role): void
    {
        $authenticate = $this->createMock(AuthenticateTokenUseCase::class);
        $authenticate->expects($this->once())->method('execute')->with('token-valido')
            ->willReturn(new AuthenticatedUser(1, 'Usuario de prueba', 'usuario@example.invalid', $role));
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withHeader('Authorization', 'Bearer token-valido');

        self::assertSame(200, $this->app($authenticate)->handle($request)->getStatusCode());
    }

    public static function rutas(): iterable
    {
        foreach (['geography', 'sedes', 'vehicle-types', 'failure-reasons', 'catalogs'] as $name) {
            yield '/api/v1/' . $name => ['/api/v1/' . $name];
            yield '/' . $name => ['/' . $name];
        }
    }

    public static function tokensRechazados(): iterable
    {
        foreach (self::rutas() as [$path]) {
            foreach ([
                InvalidToken::class => 'Token inválido',
                TokenExpired::class => 'Token expirado',
                UserNotAllowed::class => 'Usuario inactivo o inexistente',
            ] as $exception => $message) {
                yield $path . ' ' . $exception => [$path, $exception, $message];
            }
        }
    }

    public static function usuariosAutenticados(): iterable
    {
        foreach (self::rutas() as [$path]) {
            foreach (['OPERADOR', 'CONDUCTOR', 'ADMINISTRADOR'] as $role) {
                yield $path . ' ' . $role => [$path, $role];
            }
        }
    }

    private function app(AuthenticateTokenUseCase $authenticate, bool $mustNotReadCatalogs = false): App
    {
        $app = AppFactory::create();

        $geoUseCase = $this->catalogUseCase(GetGeographyUseCase::class, $mustNotReadCatalogs);
        $geoAction = new GetGeographyAction($geoUseCase);

        $sedesUseCase = $this->catalogUseCase(GetSedesUseCase::class, $mustNotReadCatalogs);
        $sedesAction = new GetSedesAction($sedesUseCase);

        $vehUseCase = $this->catalogUseCase(GetVehicleTypesUseCase::class, $mustNotReadCatalogs);
        $vehAction = new GetVehicleTypesAction($vehUseCase);

        $failUseCase = $this->catalogUseCase(GetFailureReasonsUseCase::class, $mustNotReadCatalogs);
        $failAction = new GetFailureReasonsAction($failUseCase);

        $registerRoutes = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/CatalogRoutes.php';
        $catalogsAction = new GetCatalogsAction($geoUseCase, $vehUseCase, $failUseCase);

        $registerRoutes($app, $geoAction, $sedesAction, $vehAction, $failAction, $catalogsAction, new JwtAuthMiddleware($authenticate));

        return $app;
    }

    private function catalogUseCase(string $interface, bool $mustNotReadCatalogs): object
    {
        if ($mustNotReadCatalogs) {
            $useCase = $this->createMock($interface);
            $useCase->expects($this->never())->method('execute');

            return $useCase;
        }
        $useCase = $this->createStub($interface);
        $useCase->method('execute')->willReturn([]);

        return $useCase;
    }
}
