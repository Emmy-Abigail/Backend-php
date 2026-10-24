<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

/** Tambien aplica a respuestas de error y rechazos de autenticacion. */
final readonly class PrivateEvidenceMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (\Throwable) {
            // Incluye fallos de autenticacion/BD anteriores al action. El manejador
            // global de desarrollo no debe exponer detalles de este recurso privado.
            error_log('Fallo al procesar una consulta privada de evidencia');
            $response = new Response(500);
            $response->getBody()->write('{"error":"error_interno","message":"No se pudo consultar la evidencia"}');
            $response = $response->withHeader('Content-Type', 'application/json');
        }
        $response = $response->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            ->withAddedHeader('Vary', 'Authorization')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        return strtoupper($request->getMethod()) === 'HEAD'
            ? $response->withBody((new StreamFactory())->createStream()) : $response;
    }
}
