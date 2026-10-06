<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Middleware;

use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class MustChangePasswordMiddlewareTest extends TestCase
{
    #[Test]
    public function userWithoutMustChangePasswordPassesThrough(): void
    {
        $middleware = new MustChangePasswordMiddleware();
        $authUser = new AuthenticatedUser(1, 'Admin', 'admin@email.com', 'ADMINISTRADOR', false);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/users')
            ->withAttribute('authUser', $authUser);

        $handlerResponse = (new ResponseFactory())->createResponse(200);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($handlerResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function userWithMustChangePasswordCanAccessMeEndpoint(): void
    {
        $middleware = new MustChangePasswordMiddleware();
        $authUser = new AuthenticatedUser(2, 'Conductor', 'chofer@email.com', 'CONDUCTOR', true);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/auth/me')
            ->withAttribute('authUser', $authUser);

        $handlerResponse = (new ResponseFactory())->createResponse(200);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($handlerResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function userWithMustChangePasswordCanAccessChangePasswordEndpoint(): void
    {
        $middleware = new MustChangePasswordMiddleware();
        $authUser = new AuthenticatedUser(2, 'Conductor', 'chofer@email.com', 'CONDUCTOR', true);

        $request = (new ServerRequestFactory())->createServerRequest('PATCH', '/api/v1/auth/change-password')
            ->withAttribute('authUser', $authUser);

        $handlerResponse = (new ResponseFactory())->createResponse(200);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn($handlerResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function userWithMustChangePasswordIsBlockedFromOtherProtectedRoutes(): void
    {
        $middleware = new MustChangePasswordMiddleware();
        $authUser = new AuthenticatedUser(2, 'Conductor', 'chofer@email.com', 'CONDUCTOR', true);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/users')
            ->withAttribute('authUser', $authUser);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Debes cambiar tu contraseña', $body['message']);
        $this->assertSame('cambio_password_obligatorio', $body['error']);
    }
}