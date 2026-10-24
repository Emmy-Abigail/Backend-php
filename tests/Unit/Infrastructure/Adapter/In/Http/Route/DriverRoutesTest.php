<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\StartRouteResult;
use App\Application\Port\In\Batch\StartRouteUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Batch\StartRouteAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DriverRoutesTest extends TestCase
{
    public static function permissions(): array
    {
        return [
            ['CONDUCTOR', false, true, 200],
            ['CONDUCTOR', false, false, 401],
            ['CONDUCTOR', true, true, 403],
            ['OPERADOR', false, true, 403],
            ['ADMINISTRADOR', false, true, 403],
        ];
    }

    #[DataProvider('permissions')]
    public function test_protege_el_inicio_con_jwt_rol_y_cambio_de_password(string $role, bool $mustChange, bool $token, int $status): void
    {
        $useCase = $this->createMock(StartRouteUseCase::class);
        $useCase->expects($status === 200 ? $this->once() : $this->never())->method('execute')
            ->willReturn(new StartRouteResult(210, 'L-ABC123', new DateTimeImmutable('2026-10-24T15:30:00Z'), 2));
        $authenticate = $this->createStub(AuthenticateTokenUseCase::class);
        $authenticate->method('execute')->willReturn(new AuthenticatedUser(31, 'Juan', 'juan@example.invalid', $role, $mustChange));

        $app = AppFactory::create();
        $register = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/DriverRoutes.php';
        $register($app, new StartRouteAction($useCase), new JwtAuthMiddleware($authenticate), new RoleMiddleware('CONDUCTOR'), new MustChangePasswordMiddleware());
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/batches/210/start-route');
        if ($token) {
            $request = $request->withHeader('Authorization', 'Bearer test-token');
        }
        $response = $app->handle($request);
        self::assertSame($status, $response->getStatusCode());
        if ($mustChange) {
            self::assertSame('cambio_password_obligatorio', json_decode((string) $response->getBody(), true)['error']);
        }
    }
}
