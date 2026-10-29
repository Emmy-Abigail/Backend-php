<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Exception\AssignmentUnavailable;
use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageAlreadyRetried;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\PackageNotRetryable;
use App\Application\Exception\PricingUnavailable;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Package\GetPackageQuery;
use App\Application\Port\In\Package\GetPackageResult;
use App\Application\Port\In\Package\GetPackageUseCase;
use App\Application\Port\In\Package\ListPackagesQuery;
use App\Application\Port\In\Package\ListPackagesResult;
use App\Application\Port\In\Package\ListPackagesUseCase;
use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\In\Package\RegisterPackageUseCase;
use App\Application\Port\In\Package\RetryPackageCommand;
use App\Application\Port\In\Package\RetryPackageUseCase;
use App\Application\Port\Out\Pricing\PackageQuote;
use App\Infrastructure\Adapter\In\Http\Action\Package\GetPackageAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\ListPackagesAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\RegisterPackageAction;
use App\Infrastructure\Adapter\In\Http\Action\Package\RetryPackageAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\PackageFixture;

require_once __DIR__ . '/../../../../../../Support/PackageFixture.php';

final class PackageRoutesTest extends TestCase
{
    private function app(RegisterPackageUseCase $useCase, string $role = 'OPERADOR', bool $mustChange = false, ?ListPackagesUseCase $listUseCase = null, ?GetPackageUseCase $getUseCase = null, ?RetryPackageUseCase $retryUseCase = null): App
    {
        $auth = $this->createStub(AuthenticateTokenUseCase::class);
        $auth->method('execute')->willReturn(new AuthenticatedUser(2, 'Operator', 'operator@example.invalid', $role, $mustChange, 1));
        $app = AppFactory::create();
        $register = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/PackageRoutes.php';
        $register($app, new RegisterPackageAction($useCase), new ListPackagesAction($listUseCase ?? $this->createStub(ListPackagesUseCase::class)), new GetPackageAction($getUseCase ?? $this->createStub(GetPackageUseCase::class)), new JwtAuthMiddleware($auth), new RoleMiddleware('OPERADOR'), new MustChangePasswordMiddleware(), new RoleMiddleware('OPERADOR', 'CONDUCTOR'), new RetryPackageAction($retryUseCase ?? $this->createStub(RetryPackageUseCase::class)));

        return $app;
    }

    private function request(bool $token = true, ?array $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/packages')->withParsedBody($body ?? PackageFixture::body());

        return $token ? $request->withHeader('Authorization', 'Bearer test-token') : $request;
    }

    #[DataProvider('unauthorizedUsers')]
    public function test_blocks_unauthorized_requests_before_the_use_case(string $role, bool $mustChange, bool $token, int $expected): void
    {
        $useCase = $this->createMock(RegisterPackageUseCase::class);
        $useCase->expects($this->never())->method('execute');
        self::assertSame($expected, $this->app($useCase, $role, $mustChange)->handle($this->request($token))->getStatusCode());
    }

    public static function unauthorizedUsers(): array
    {
        return [['OPERADOR', false, false, 401], ['CONDUCTOR', false, true, 403], ['ADMINISTRADOR', false, true, 403], ['OPERADOR', true, true, 403]];
    }

    public function test_rejects_user_supplied_operational_fields(): void
    {
        $useCase = $this->createMock(RegisterPackageUseCase::class);
        $useCase->expects($this->never())->method('execute');
        $body = PackageFixture::body();
        $body['id_sede'] = 5;
        $response = $this->app($useCase)->handle($this->request(body: $body));
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('id_sede', json_decode((string) $response->getBody(), true)['campos']);
    }

    public function test_valid_registration_returns_503_when_pricing_is_not_available(): void
    {
        $useCase = $this->createStub(RegisterPackageUseCase::class);
        $useCase->method('execute')->willThrowException(new PricingUnavailable());
        $response = $this->app($useCase)->handle($this->request());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('cotizacion_no_disponible', json_decode((string) $response->getBody(), true)['error']);
    }

    public function test_upstream_authentication_failure_is_a_503_not_an_operator_401(): void
    {
        $useCase = $this->createStub(RegisterPackageUseCase::class);
        $useCase->method('execute')->willThrowException(new AssignmentUnavailable('private upstream error'));
        $response = $this->app($useCase)->handle($this->request());
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('private upstream', (string) $response->getBody());
    }

    public function test_returns_tracking_quote_and_batch_on_success(): void
    {
        $useCase = $this->createMock(RegisterPackageUseCase::class);
        $useCase->expects($this->once())->method('execute')->with($this->callback(static fn ($command): bool => $command->operator->idSede === 1 && $command->operator->id === 2))
            ->willReturn(new RegisterPackageResult(10, 'AB23456789', '2026-10-21T12:00:00+00:00', new PackageQuote(1, '15.50'), ['id' => 20, 'estado' => 'EN_PREPARACION']));
        $response = $this->app($useCase)->handle($this->request());
        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('AB23456789', $body['tracking_id']);
        self::assertSame('EN_ALMACEN', $body['estado']);
        self::assertSame('2026-10-21T07:00:00-05:00', $body['fecha_ingreso']);
        self::assertSame(20, $body['lote']['id']);
    }

    public function test_other_package_endpoints_remain_unimplemented(): void
    {
        $app = $this->app($this->createStub(RegisterPackageUseCase::class));
        foreach (['/api/v1/packages/1/history'] as $path) {
            self::assertSame(501, $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path))->getStatusCode());
        }
    }

    private function listRequest(string $query = '', bool $token = true): \Psr\Http\Message\ServerRequestInterface
    {
        parse_str($query, $params);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/packages?' . $query)->withQueryParams($params);

        return $token ? $request->withHeader('Authorization', 'Bearer test-token') : $request;
    }

    #[DataProvider('unauthorizedUsers')]
    public function test_list_blocks_unauthorized_requests_before_the_use_case(string $role, bool $mustChange, bool $token, int $expected): void
    {
        $listUseCase = $this->createMock(ListPackagesUseCase::class);
        $listUseCase->expects($this->never())->method('execute');
        $app = $this->app($this->createStub(RegisterPackageUseCase::class), $role, $mustChange, $listUseCase);
        self::assertSame($expected, $app->handle($this->listRequest(token: $token))->getStatusCode());
    }

    public function test_list_uses_the_operator_sede_and_returns_data_with_pagination(): void
    {
        $listUseCase = $this->createMock(ListPackagesUseCase::class);
        $listUseCase->expects($this->once())->method('execute')
            ->with($this->callback(static fn (ListPackagesQuery $q): bool => $q->operator->idSede === 1
                && $q->search === '7123'
                && $q->status === 'EN_ALMACEN'
                && $q->from === '2026-10-01'
                && $q->to === '2026-10-31'
                && $q->page === 2
                && $q->perPage === 5))
            ->willReturn(new ListPackagesResult([['id' => 10, 'tracking_id' => 'AB23456789']], 6, 2, 5));
        $app = $this->app($this->createStub(RegisterPackageUseCase::class), listUseCase: $listUseCase);

        $response = $app->handle($this->listRequest('q=+7123+&estado=en_almacen&desde=2026-10-01&hasta=2026-10-31&page=2&per_page=5&id_sede=9'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('AB23456789', $body['data'][0]['tracking_id']);
        self::assertSame(['page' => 2, 'per_page' => 5, 'total' => 6, 'total_pages' => 2], $body['meta']);
    }

    public function test_list_uses_default_pagination(): void
    {
        $listUseCase = $this->createMock(ListPackagesUseCase::class);
        $listUseCase->expects($this->once())->method('execute')
            ->with($this->callback(static fn (ListPackagesQuery $q): bool => $q->page === 1 && $q->perPage === 20 && $q->search === null && $q->status === null))
            ->willReturn(new ListPackagesResult([], 0, 1, 20));
        $app = $this->app($this->createStub(RegisterPackageUseCase::class), listUseCase: $listUseCase);

        $response = $app->handle($this->listRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => [], 'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 0]], json_decode((string) $response->getBody(), true));
    }

    #[DataProvider('invalidListParams')]
    public function test_list_rejects_invalid_params(string $query, string $field): void
    {
        $listUseCase = $this->createMock(ListPackagesUseCase::class);
        $listUseCase->expects($this->never())->method('execute');
        $app = $this->app($this->createStub(RegisterPackageUseCase::class), listUseCase: $listUseCase);

        $response = $app->handle($this->listRequest($query));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey($field, json_decode((string) $response->getBody(), true)['campos']);
    }

    public static function invalidListParams(): array
    {
        return [
            ['estado=PERDIDO', 'estado'],
            ['desde=21-10-2026', 'desde'],
            ['hasta=2026-02-30', 'hasta'],
            ['desde=2026-10-31&hasta=2026-10-01', 'desde'],
            ['page=0', 'page'],
            ['page=abc', 'page'],
            ['per_page=101', 'per_page'],
            ['q=' . str_repeat('a', 101), 'q'],
            ['estado[]=EN_RUTA', 'estado'],
            ['q[]=ana', 'q'],
            ['desde[]=2026-10-24', 'desde'],
            ['hasta[]=2026-10-24', 'hasta'],
            ['page[]=2', 'page'],
            ['per_page[]=20', 'per_page'],
            ['page=99999999999999999999999999999999', 'page'],
            ['per_page=99999999999999999999999999999999', 'per_page'],
            ['page=' . PHP_INT_MAX, 'page'],
            ['page=' . (intdiv(PHP_INT_MAX, 20) + 2), 'page'],
        ];
    }

    public function test_list_accepts_the_largest_page_with_a_safe_offset(): void
    {
        $page = intdiv(PHP_INT_MAX, 20) + 1;
        $listUseCase = $this->createMock(ListPackagesUseCase::class);
        $listUseCase->expects($this->once())->method('execute')
            ->with($this->callback(static fn (ListPackagesQuery $query): bool => $query->page === $page && $query->perPage === 20))
            ->willReturn(new ListPackagesResult([], 0, $page, 20));
        $response = $this->app($this->createStub(RegisterPackageUseCase::class), listUseCase: $listUseCase)
            ->handle($this->listRequest('page=' . $page));

        self::assertSame(200, $response->getStatusCode());
    }

    private function detailRequest(string $id = '1', bool $token = true): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/packages/' . $id);

        return $token ? $request->withHeader('Authorization', 'Bearer test-token') : $request;
    }

    #[DataProvider('detailAccess')]
    public function test_detail_checks_token_role_and_password_change_before_the_use_case(string $role, bool $mustChange, bool $token, int $expected): void
    {
        $getUseCase = $this->createMock(GetPackageUseCase::class);
        $getUseCase->expects($this->never())->method('execute');
        $app = $this->app($this->createStub(RegisterPackageUseCase::class), $role, $mustChange, getUseCase: $getUseCase);
        self::assertSame($expected, $app->handle($this->detailRequest(token: $token))->getStatusCode());
    }

    public static function detailAccess(): array
    {
        return [['OPERADOR', false, false, 401], ['ADMINISTRADOR', false, true, 403], ['CONDUCTOR', true, true, 403]];
    }

    #[DataProvider('detailViewers')]
    public function test_detail_lets_operators_and_drivers_reach_the_use_case(string $role): void
    {
        $getUseCase = $this->createMock(GetPackageUseCase::class);
        $getUseCase->expects($this->once())->method('execute')
            ->with($this->callback(static fn (GetPackageQuery $q): bool => $q->packageId === 15 && $q->viewer->role === $role))
            ->willReturn(new GetPackageResult(['id' => 15, 'tracking_id' => 'AB23456789', 'historial' => []]));
        $app = $this->app($this->createStub(RegisterPackageUseCase::class), $role, getUseCase: $getUseCase);

        $response = $app->handle($this->detailRequest('15'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('AB23456789', json_decode((string) $response->getBody(), true)['tracking_id']);
    }

    public static function detailViewers(): array
    {
        return [['OPERADOR'], ['CONDUCTOR']];
    }

    #[DataProvider('invalidIds')]
    public function test_detail_rejects_invalid_ids(string $id): void
    {
        $getUseCase = $this->createMock(GetPackageUseCase::class);
        $getUseCase->expects($this->never())->method('execute');
        $response = $this->app($this->createStub(RegisterPackageUseCase::class), getUseCase: $getUseCase)->handle($this->detailRequest($id));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('id_invalido', json_decode((string) $response->getBody(), true)['error']);
    }

    public static function invalidIds(): array
    {
        return [['0'], ['abc'], ['-3'], ['1.5']];
    }

    public function test_detail_maps_not_found_and_access_denied(): void
    {
        $getUseCase = $this->createStub(GetPackageUseCase::class);
        $getUseCase->method('execute')->willReturnOnConsecutiveCalls(
            $this->throwException(new PackageNotFound('El paquete no existe')),
            $this->throwException(new PackageAccessDenied('No puede ver paquetes de otra sede')),
        );
        $app = $this->app($this->createStub(RegisterPackageUseCase::class), getUseCase: $getUseCase);

        $notFound = $app->handle($this->detailRequest('99'));
        self::assertSame(404, $notFound->getStatusCode());
        self::assertSame('paquete_no_encontrado', json_decode((string) $notFound->getBody(), true)['error']);

        $denied = $app->handle($this->detailRequest('7'));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('sin_permisos', json_decode((string) $denied->getBody(), true)['error']);
    }

    private function retryRequest(string $id = '7', ?array $body = null, bool $token = true): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/packages/' . $id . '/retry');
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $token ? $request->withHeader('Authorization', 'Bearer test-token') : $request;
    }

    #[DataProvider('unauthorizedUsers')]
    public function test_retry_blocks_unauthorized_requests_before_the_use_case(string $role, bool $mustChange, bool $token, int $expected): void
    {
        $retryUseCase = $this->createMock(RetryPackageUseCase::class);
        $retryUseCase->expects($this->never())->method('execute');
        $app = $this->app($this->createStub(RegisterPackageUseCase::class), $role, $mustChange, retryUseCase: $retryUseCase);
        self::assertSame($expected, $app->handle($this->retryRequest(token: $token))->getStatusCode());
    }

    public function test_retry_without_body_returns_the_new_package_and_its_origin(): void
    {
        $retryUseCase = $this->createMock(RetryPackageUseCase::class);
        $retryUseCase->expects($this->once())->method('execute')
            ->with($this->callback(static fn (RetryPackageCommand $c): bool => $c->packageId === 7 && $c->corrections === [] && $c->operator->idSede === 1))
            ->willReturn(new RegisterPackageResult(30, 'CD23456789', '2026-10-28T12:00:00+00:00', new PackageQuote(1, '12.50'), ['id' => 4, 'estado' => 'EN_PREPARACION']));
        $response = $this->app($this->createStub(RegisterPackageUseCase::class), retryUseCase: $retryUseCase)->handle($this->retryRequest());

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('CD23456789', $body['tracking_id']);
        self::assertSame(7, $body['id_paquete_origen']);
        self::assertSame('EN_ALMACEN', $body['estado']);
    }

    public function test_retry_passes_the_allowed_corrections(): void
    {
        $corrections = ['direccion' => ['nombre_via' => 'Larco'], 'destinatario' => ['celular' => '998877665']];
        $retryUseCase = $this->createMock(RetryPackageUseCase::class);
        $retryUseCase->expects($this->once())->method('execute')
            ->with($this->callback(static fn (RetryPackageCommand $c): bool => $c->corrections === $corrections))
            ->willReturn(new RegisterPackageResult(30, 'CD23456789', '2026-10-28T12:00:00+00:00', new PackageQuote(1, '12.50'), []));
        $response = $this->app($this->createStub(RegisterPackageUseCase::class), retryUseCase: $retryUseCase)->handle($this->retryRequest(body: $corrections));

        self::assertSame(201, $response->getStatusCode());
    }

    #[DataProvider('invalidRetryBodies')]
    public function test_retry_rejects_fields_that_cannot_be_corrected(array $body, string $field): void
    {
        $retryUseCase = $this->createMock(RetryPackageUseCase::class);
        $retryUseCase->expects($this->never())->method('execute');
        $response = $this->app($this->createStub(RegisterPackageUseCase::class), retryUseCase: $retryUseCase)->handle($this->retryRequest(body: $body));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey($field, json_decode((string) $response->getBody(), true)['campos']);
    }

    public static function invalidRetryBodies(): array
    {
        return [
            'peso' => [['peso_kg' => 1], 'peso_kg'],
            'remitente' => [['remitente' => ['nombres' => 'Otro']], 'remitente'],
            'nombre del destinatario' => [['destinatario' => ['nombres' => 'Otra persona']], 'destinatario.nombres'],
            'direccion no es objeto' => [['direccion' => 'Av. Larco 123'], 'direccion'],
        ];
    }

    public function test_retry_rejects_an_invalid_id(): void
    {
        $retryUseCase = $this->createMock(RetryPackageUseCase::class);
        $retryUseCase->expects($this->never())->method('execute');
        $response = $this->app($this->createStub(RegisterPackageUseCase::class), retryUseCase: $retryUseCase)->handle($this->retryRequest('abc'));
        self::assertSame(422, $response->getStatusCode());
    }

    #[DataProvider('retryErrors')]
    public function test_retry_maps_business_errors(\Throwable $error, int $status, string $code): void
    {
        $retryUseCase = $this->createStub(RetryPackageUseCase::class);
        $retryUseCase->method('execute')->willThrowException($error);
        $response = $this->app($this->createStub(RegisterPackageUseCase::class), retryUseCase: $retryUseCase)->handle($this->retryRequest());

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, json_decode((string) $response->getBody(), true)['error']);
    }

    public static function retryErrors(): array
    {
        return [
            'no existe' => [new PackageNotFound('El paquete no existe'), 404, 'paquete_no_encontrado'],
            'otra sede' => [new PackageAccessDenied('Otra sede'), 403, 'sin_permisos'],
            'no fallido' => [new PackageNotRetryable('Solo FALLIDO'), 409, 'reintento_no_permitido'],
            'ya reintentado' => [new PackageAlreadyRetried('Ya tiene reintento'), 409, 'reintento_existente'],
            'python caido' => [new AssignmentUnavailable('detalle interno'), 503, 'asignacion_no_disponible'],
        ];
    }
}
