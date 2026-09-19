<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\User\CreateUserResult;
use App\Application\Port\In\User\CreateUserUseCase;
use App\Application\Port\In\User\ListUsersResult;
use App\Application\Port\In\User\ListUsersUseCase;
use App\Infrastructure\Adapter\In\Http\Action\User\CreateUserAction;
use App\Infrastructure\Adapter\In\Http\Action\User\ListUsersAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class UserRoutesTest extends TestCase
{
    #[Test]
    public function rechaza_crear_usuarios_sin_token(): void
    {
        $app = $this->appForRole('Admin');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/users');

        self::assertSame(401, $app->handle($request)->getStatusCode());
    }

    #[Test]
    public function rechaza_a_un_conductor_aun_con_token_valido(): void
    {
        $app = $this->appForRole('Conductor');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/users')
            ->withHeader('Authorization', 'Bearer token-valido');

        self::assertSame(403, $app->handle($request)->getStatusCode());
    }

    #[Test]
    public function permite_a_un_admin_listar_el_personal(): void
    {
        $app = $this->appForRole('Admin');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/users')
            ->withHeader('Authorization', 'Bearer token-valido');

        self::assertSame(200, $app->handle($request)->getStatusCode());
    }

    private function appForRole(string $role): \Slim\App
    {
        $useCase = $this->createStub(CreateUserUseCase::class);
        $useCase->method('execute')->willReturn(new CreateUserResult(2, 'Usuario', 'usuario@email.com', 'Conductor', 'Temporal1!abc'));
        $action = new CreateUserAction($useCase);

        $listUsersUseCase = $this->createStub(ListUsersUseCase::class);
        $listUsersUseCase->method('execute')->willReturn(new ListUsersResult([]));
        $listUsersAction = new ListUsersAction($listUsersUseCase);

        $authenticateToken = $this->createStub(AuthenticateTokenUseCase::class);
        $authenticateToken->method('execute')->willReturn(new AuthenticatedUser(1, 'Admin', 'admin@email.com', $role));

        $app = AppFactory::create();
        $registerRoutes = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/UserRoutes.php';
        $registerRoutes(
            $app,
            $action,
            $listUsersAction,
            new JwtAuthMiddleware($authenticateToken),
            new RoleMiddleware('Admin'),
        );

        return $app;
    }
}
