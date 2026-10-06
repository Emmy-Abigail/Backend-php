<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Middleware;

use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class ErrorHandlerTest extends TestCase
{
    #[Test]
    public function notFoundExceptionProducesJson404(): void
    {
        $app = AppFactory::create();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/ruta-inexistente');
        $exception = new HttpNotFoundException($request);

        $response = $app->getResponseFactory()->createResponse(404);
        $payload = [
            'message' => 'Ruta no encontrada',
            'error' => 'no_encontrado',
        ];
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        $response = $response->withHeader('Content-Type', 'application/json');

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Ruta no encontrada', $body['message']);
        $this->assertSame('no_encontrado', $body['error']);
    }

    #[Test]
    public function methodNotAllowedExceptionProducesJson405(): void
    {
        $app = AppFactory::create();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/health');
        $exception = new HttpMethodNotAllowedException($request);

        $response = $app->getResponseFactory()->createResponse(405);
        $payload = [
            'message' => 'Método no permitido',
            'error' => 'metodo_no_permitido',
        ];
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        $response = $response->withHeader('Content-Type', 'application/json');

        $this->assertSame(405, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('Método no permitido', $body['message']);
        $this->assertSame('metodo_no_permitido', $body['error']);
    }
}