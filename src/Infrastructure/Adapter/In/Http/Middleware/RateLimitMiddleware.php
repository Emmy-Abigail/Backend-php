<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Middleware;

use App\Application\Port\Out\Security\RateLimiter;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param string $scope Separa los contadores de cada endpoint limitado.
     * @param Closure(ServerRequestInterface): string|null $discriminator Si se indica, el contador es por IP y por el
     *        texto que devuelva (p. ej. el correo del login), para que unos usuarios no bloqueen a otros.
     */
    public function __construct(
        private RateLimiter $limiter,
        private string $scope,
        private ?Closure $discriminator = null,
    ) {
    }

    /** Cuenta por IP y por el valor (en minúsculas) de un campo del cuerpo; el valor se guarda con hash. */
    public static function perBodyField(RateLimiter $limiter, string $scope, string $field): self
    {
        return new self($limiter, $scope, static function (ServerRequestInterface $request) use ($field): string {
            $body = $request->getParsedBody();
            $value = is_array($body) && is_string($body[$field] ?? null) ? strtolower(trim($body[$field])) : '';

            return hash('sha256', $value);
        });
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Solo REMOTE_ADDR: X-Forwarded-For lo puede inventar el cliente para saltarse el límite.
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'desconocida';
        $key = $this->scope . '|' . $ip;
        if ($this->discriminator !== null) {
            $key .= '|' . ($this->discriminator)($request);
        }
        $decision = $this->limiter->hit($key);

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
