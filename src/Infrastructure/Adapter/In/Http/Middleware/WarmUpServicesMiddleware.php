<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Middleware;

use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Al recibir un login envia un ping corto a los microservicios para que el
 * hosting los despierte mientras el usuario entra al sistema.
 * El resultado del ping se ignora: el login nunca depende de Python.
 */
final readonly class WarmUpServicesMiddleware implements MiddlewareInterface
{
    /** @param list<string> $urls */
    public function __construct(
        private HttpTransport $transport,
        private array $urls,
        private float $timeoutSeconds = 1.0,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        foreach ($this->urls as $url) {
            try {
                $this->transport->get($url, $this->timeoutSeconds);
            } catch (Throwable) {
                // Si el servicio esta dormido el ping vence pronto, pero la peticion
                // ya llego al hosting y el servicio empieza a arrancar.
            }
        }

        return $handler->handle($request);
    }
}
