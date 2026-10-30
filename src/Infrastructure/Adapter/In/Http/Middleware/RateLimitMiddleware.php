<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Middleware;

use App\Application\Port\Out\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    /** @param string $scope Separa los contadores de cada endpoint limitado. */
    public function __construct(private RateLimiter $limiter, private string $scope)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Solo REMOTE_ADDR: X-Forwarded-For lo puede inventar el cliente para saltarse el límite.
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'desconocida';
        $decision = $this->limiter->hit($this->scope . '|' . $ip);

        if (!$decision->allowed) {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'error' => 'demasiadas_consultas',
                'message' => 'Demasiadas consultas. Intenta de nuevo en ' . $decision->retryAfterSeconds . ' segundos',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) $decision->retryAfterSeconds)
                ->withHeader('X-RateLimit-Limit', (string) $decision->limit)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withStatus(429);
        }

        return $handler->handle($request)
            ->withHeader('X-RateLimit-Limit', (string) $decision->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $decision->remaining);
    }
}
