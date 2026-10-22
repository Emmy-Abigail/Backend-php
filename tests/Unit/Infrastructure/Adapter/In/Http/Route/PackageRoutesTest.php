<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Exception\AssignmentUnavailable;
use App\Application\Exception\PricingUnavailable;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\In\Package\RegisterPackageUseCase;
use App\Application\Port\Out\Pricing\PackageQuote;
use App\Infrastructure\Adapter\In\Http\Action\Package\RegisterPackageAction;
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
    private function app(RegisterPackageUseCase $useCase, string $role = 'OPERADOR', bool $mustChange = false): App
    {
        $auth = $this->createStub(AuthenticateTokenUseCase::class);
        $auth->method('execute')->willReturn(new AuthenticatedUser(2, 'Operator', 'operator@example.invalid', $role, $mustChange, 1));
        $app = AppFactory::create();
        $register = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/PackageRoutes.php';
        $register($app, new RegisterPackageAction($useCase), new JwtAuthMiddleware($auth), new RoleMiddleware('OPERADOR'), new MustChangePasswordMiddleware());

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
        self::assertSame(20, $body['lote']['id']);
    }

    public function test_other_package_endpoints_remain_unimplemented(): void
    {
        $app = $this->app($this->createStub(RegisterPackageUseCase::class));
        foreach (['/api/v1/packages', '/api/v1/packages/1', '/api/v1/packages/1/history'] as $path) {
            self::assertSame(501, $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path))->getStatusCode());
        }
    }
}
