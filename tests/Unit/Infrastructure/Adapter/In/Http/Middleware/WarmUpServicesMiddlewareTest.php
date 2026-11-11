<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Middleware;

use App\Infrastructure\Adapter\In\Http\Middleware\WarmUpServicesMiddleware;
use App\Infrastructure\Adapter\Out\Http\HttpResponse;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class WarmUpServicesMiddlewareTest extends TestCase
{
    #[Test]
    public function envia_el_ping_y_continua_con_el_login(): void
    {
        $transport = new RecordingTransport();

        $response = (new WarmUpServicesMiddleware($transport, ['http://python/docs'], 1.0))
            ->process($this->loginRequest(), $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['http://python/docs', 1.0]], $transport->calls);
    }

    #[Test]
    public function si_python_no_responde_el_login_sigue_funcionando(): void
    {
        $transport = new RecordingTransport(fail: true);

        $response = (new WarmUpServicesMiddleware($transport, ['http://python/docs']))
            ->process($this->loginRequest(), $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $transport->calls);
    }

    #[Test]
    public function sin_urls_configuradas_no_envia_pings(): void
    {
        $transport = new RecordingTransport();

        $response = (new WarmUpServicesMiddleware($transport, []))
            ->process($this->loginRequest(), $this->okHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $transport->calls);
    }

    private function loginRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/auth/login');
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }
}

final class RecordingTransport implements HttpTransport
{
    /** @var list<array{0: string, 1: float}> */
    public array $calls = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function post(string $url, array $payload, string $apiKey, float $timeout): HttpResponse
    {
        throw new RuntimeException('No se usa en esta prueba');
    }

    public function get(string $url, float $timeout): HttpResponse
    {
        $this->calls[] = [$url, $timeout];
        if ($this->fail) {
            throw new RuntimeException('timeout');
        }

        return new HttpResponse(200, '');
    }
}
