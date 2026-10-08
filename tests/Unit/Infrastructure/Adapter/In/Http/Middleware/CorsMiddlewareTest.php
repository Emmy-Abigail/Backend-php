<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Middleware;

use App\Infrastructure\Adapter\In\Http\Middleware\CorsMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class CorsMiddlewareTest extends TestCase
{
    #[Test]
    public function optionsRequestReturns204WithCorsHeaders(): void
    {
        $middleware = new CorsMiddleware('http://localhost:4200');
        $request = (new ServerRequestFactory())->createServerRequest('OPTIONS', '/api/v1/auth/login');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($request, $handler);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('http://localhost:4200', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    #[Test]
    public function nonOptionsRequestPassesToHandlerAndAttachesCorsHeaders(): void
    {
        $middleware = new CorsMiddleware('http://localhost:4200');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');

        $handlerResponse = (new ResponseFactory())->createResponse(200);
        $handlerResponse->getBody()->write(json_encode(['status' => 'ok']));

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($handlerResponse);

        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('http://localhost:4200', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('GET', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function defaultOriginFallsBackToLocalhost4200(): void
    {
        unset($_ENV['FRONTEND_URL']);
        $middleware = new CorsMiddleware();
        $request = (new ServerRequestFactory())->createServerRequest('OPTIONS', '/health');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $response = $middleware->process($request, $handler);

        $this->assertSame('http://localhost:4200', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}