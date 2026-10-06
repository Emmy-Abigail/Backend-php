<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    private readonly string $allowedOrigin;

    public function __construct(?string $allowedOrigin = null)
    {
        $this->allowedOrigin = $allowedOrigin ?: ($_ENV['FRONTEND_URL'] ?? 'http://localhost:4200');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = new Response(204);
            return $this->withCorsHeaders($response);
        }

        $response = $handler->handle($request);
        return $this->withCorsHeaders($response);
    }

    private function withCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $this->allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, Origin, X-Requested-With')
            ->withHeader('Access-Control-Max-Age', '86400');
    }
}