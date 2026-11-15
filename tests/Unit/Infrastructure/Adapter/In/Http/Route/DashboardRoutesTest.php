<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Dashboard\DashboardMetricsResult;
use App\Application\Port\In\Dashboard\FailedDeliveriesResult;
use App\Application\Port\In\Dashboard\GetDashboardMetricsQuery;
use App\Application\Port\In\Dashboard\GetDashboardMetricsUseCase;
use App\Application\Port\In\Dashboard\ListFailedDeliveriesQuery;
use App\Application\Port\In\Dashboard\ListFailedDeliveriesUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Dashboard\GetDashboardMetricsAction;
use App\Infrastructure\Adapter\In\Http\Action\Dashboard\ListFailedDeliveriesAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DashboardRoutesTest extends TestCase
{
    private const METRICS = '/api/v1/dashboard/metrics';
    private const FAILED = '/api/v1/dashboard/failed-deliveries';
    private const RANGE = ['desde' => '2026-10-01', 'hasta' => '2026-10-31'];

    private ?GetDashboardMetricsQuery $metricsQuery = null;
    private ?ListFailedDeliveriesQuery $failedQuery = null;

    /** @param array<string, mixed> $query */
    private function get(App $app, string $path, array $query = self::RANGE, bool $authorized = true): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)->withQueryParams($query);
        if ($authorized) {
            $request = $request->withHeader('Authorization', 'Bearer token-valido');
        }

        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_rechaza_las_consultas_sin_token(): void
    {
        $app = $this->appForRole('ADMINISTRADOR');

        self::assertSame(401, $this->get($app, self::METRICS, authorized: false)->getStatusCode());
        self::assertSame(401, $this->get($app, self::FAILED, authorized: false)->getStatusCode());
    }

    public function test_el_conductor_no_puede_ver_el_dashboard(): void
    {
        $app = $this->appForRole('CONDUCTOR');

        self::assertSame(403, $this->get($app, self::METRICS)->getStatusCode());
        self::assertSame(403, $this->get($app, self::FAILED)->getStatusCode());
    }

    public function test_exige_cambiar_la_contrasena_temporal(): void
    {
        $response = $this->get($this->appForRole('OPERADOR', true), self::METRICS);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('cambio_password_obligatorio', $this->body($response)['error']);
    }

    public function test_el_administrador_y_el_operador_obtienen_las_metricas(): void
    {
        foreach (['ADMINISTRADOR', 'OPERADOR'] as $role) {
            $response = $this->get($this->appForRole($role), self::METRICS, self::RANGE + ['id_sede' => '2']);

            self::assertSame(200, $response->getStatusCode(), $role);
            self::assertSame(['kpis' => ['paquetes_registrados' => 0]], $this->body($response)['data']);
            self::assertSame('2026-10-01', $this->metricsQuery?->from);
            self::assertSame('2026-10-31', $this->metricsQuery?->to);
            self::assertSame(2, $this->metricsQuery?->sedeId);
        }
    }

    public function test_lista_los_fallidos_con_8_por_pagina_por_defecto(): void
    {
        $response = $this->get($this->appForRole('OPERADOR'), self::FAILED);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(8, $this->body($response)['meta']['per_page']);
        self::assertSame(1, $this->failedQuery?->page);
        self::assertSame(8, $this->failedQuery?->perPage);
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('parametrosInvalidos')]
    public function test_valida_los_parametros_con_422(array $query, string $field, string $message): void
    {
        $response = $this->get($this->appForRole('ADMINISTRADOR'), self::METRICS, $query);
        $body = $this->body($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('parametros_invalidos', $body['error']);
        self::assertSame($message, $body['message']);
        self::assertArrayHasKey($field, $body['campos']);
        self::assertNull($this->metricsQuery, 'No debe llegar al caso de uso');
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function parametrosInvalidos(): array
    {
        $range = 'El rango de fechas no es válido';

        return [
            'sin desde' => [['hasta' => '2026-10-31'], 'desde', $range],
            'sin hasta' => [['desde' => '2026-10-01'], 'hasta', $range],
            'formato incorrecto' => [['desde' => '01/10/2026', 'hasta' => '2026-10-31'], 'desde', $range],
            'fecha inexistente' => [['desde' => '2026-02-30', 'hasta' => '2026-03-01'], 'desde', $range],
            'desde posterior a hasta' => [['desde' => '2026-11-01', 'hasta' => '2026-10-31'], 'desde', $range],
            'rango mayor a 366 dias' => [['desde' => '2025-01-01', 'hasta' => '2026-01-02'], 'hasta', $range],
            'lista en lugar de texto' => [['desde' => ['2026-10-01'], 'hasta' => '2026-10-31'], 'desde', $range],
            'sede no numerica' => [self::RANGE + ['id_sede' => 'abc'], 'id_sede', 'Parámetros del dashboard inválidos'],
            'sede cero' => [self::RANGE + ['id_sede' => '0'], 'id_sede', 'Parámetros del dashboard inválidos'],
        ];
    }

    public function test_acepta_un_rango_de_un_solo_dia(): void
    {
        $response = $this->get($this->appForRole('OPERADOR'), self::METRICS, ['desde' => '2026-11-15', 'hasta' => '2026-11-15']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_acepta_hasta_366_dias_incluidos_y_rechaza_367(): void
    {
        $app = $this->appForRole('ADMINISTRADOR');

        $limit = $this->get($app, self::METRICS, ['desde' => '2025-11-15', 'hasta' => '2026-11-15']);
        $overLimit = $this->get($app, self::FAILED, ['desde' => '2025-11-14', 'hasta' => '2026-11-15']);

        self::assertSame(200, $limit->getStatusCode());
        self::assertSame(422, $overLimit->getStatusCode());
        self::assertArrayHasKey('hasta', $this->body($overLimit)['campos']);
    }

    public function test_un_usuario_sin_permiso_en_el_caso_de_uso_recibe_403(): void
    {
        // Por ejemplo, un operador sin sede: el rol pasa el middleware pero el servicio lo rechaza.
        $app = $this->appForRole('OPERADOR', notAllowed: true);

        foreach ([self::METRICS, self::FAILED] as $path) {
            $response = $this->get($app, $path);

            self::assertSame(403, $response->getStatusCode(), $path);
            self::assertSame('sin_permisos', $this->body($response)['error']);
        }
    }

    public function test_valida_la_paginacion_de_fallidos(): void
    {
        $app = $this->appForRole('OPERADOR');

        $tooMany = $this->get($app, self::FAILED, self::RANGE + ['per_page' => '51']);
        $badPage = $this->get($app, self::FAILED, self::RANGE + ['page' => '0']);

        self::assertSame(422, $tooMany->getStatusCode());
        self::assertArrayHasKey('per_page', $this->body($tooMany)['campos']);
        self::assertSame(422, $badPage->getStatusCode());
        self::assertArrayHasKey('page', $this->body($badPage)['campos']);
    }

    public function test_una_sede_inexistente_responde_422(): void
    {
        $response = $this->get($this->appForRole('ADMINISTRADOR', false, unknownSede: true), self::METRICS, self::RANGE + ['id_sede' => '99']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('id_sede', $this->body($response)['campos']);
    }

    private function appForRole(string $role, bool $mustChangePassword = false, bool $unknownSede = false, bool $notAllowed = false): App
    {
        $metrics = $this->createStub(GetDashboardMetricsUseCase::class);
        $metrics->method('execute')->willReturnCallback(function (GetDashboardMetricsQuery $query) use ($unknownSede, $notAllowed): DashboardMetricsResult {
            if ($notAllowed) {
                throw new UserNotAllowed('El operador no tiene una sede asignada');
            }
            if ($unknownSede) {
                throw new InvalidCatalogReference('La sede indicada no existe');
            }
            $this->metricsQuery = $query;

            return new DashboardMetricsResult(['kpis' => ['paquetes_registrados' => 0]]);
        });

        $failed = $this->createStub(ListFailedDeliveriesUseCase::class);
        $failed->method('execute')->willReturnCallback(function (ListFailedDeliveriesQuery $query) use ($notAllowed): FailedDeliveriesResult {
            if ($notAllowed) {
                throw new UserNotAllowed('El operador no tiene una sede asignada');
            }
            $this->failedQuery = $query;

            return new FailedDeliveriesResult([], 0, $query->page, $query->perPage, $query->from, $query->to, 'Sede Sur');
        });

        $authenticateToken = $this->createStub(AuthenticateTokenUseCase::class);
        $authenticateToken->method('execute')->willReturn(
            new AuthenticatedUser(5, 'Usuario', 'usuario@example.invalid', $role, $mustChangePassword, $role === 'OPERADOR' ? 2 : null, 'Sede Sur'),
        );

        $app = AppFactory::create();
        $registerRoutes = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/DashboardRoutes.php';
        $registerRoutes(
            $app,
            new GetDashboardMetricsAction($metrics),
            new ListFailedDeliveriesAction($failed),
            new JwtAuthMiddleware($authenticateToken),
            new RoleMiddleware('ADMINISTRADOR', 'OPERADOR'),
            new MustChangePasswordMiddleware(),
        );

        return $app;
    }
}
