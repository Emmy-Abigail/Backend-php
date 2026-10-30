<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Exception\PackageNotFound;
use App\Application\Port\In\Tracking\GetTrackingResult;
use App\Application\Port\In\Tracking\GetTrackingUseCase;
use App\Application\Port\Out\Security\RateLimitDecision;
use App\Application\Port\Out\Security\RateLimiter;
use App\Infrastructure\Adapter\In\Http\Action\Tracking\GetTrackingAction;
use App\Infrastructure\Adapter\In\Http\Middleware\RateLimitMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class TrackingRoutesTest extends TestCase
{
    private function app(GetTrackingUseCase $useCase, ?RateLimiter $limiter = null): App
    {
        if ($limiter === null) {
            $limiter = $this->createStub(RateLimiter::class);
            $limiter->method('hit')->willReturn(new RateLimitDecision(true, 30, 29, 0));
        }
        $app = AppFactory::create();
        $register = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/TrackingRoutes.php';
        $register($app, new GetTrackingAction($useCase), new RateLimitMiddleware($limiter, 'tracking'));

        return $app;
    }

    private function request(string $code, string $ip = '203.0.113.7'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/tracking/' . $code, ['REMOTE_ADDR' => $ip]);
    }

    public function test_responde_sin_token_y_normaliza_el_codigo_a_mayusculas(): void
    {
        $useCase = $this->createMock(GetTrackingUseCase::class);
        $useCase->expects($this->once())->method('execute')->with('AB23456789')
            ->willReturn(new GetTrackingResult(['tracking_id' => 'AB23456789', 'estado' => ['codigo' => 'EN_RUTA']]));

        $response = $this->app($useCase)->handle($this->request(' ab23456789 '));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('29', $response->getHeaderLine('X-RateLimit-Remaining'));
        self::assertSame('EN_RUTA', json_decode((string) $response->getBody(), true)['estado']['codigo']);
    }

    #[DataProvider('codigosInvalidos')]
    public function test_rechaza_codigos_con_formato_invalido_sin_consultar(string $code): void
    {
        $useCase = $this->createMock(GetTrackingUseCase::class);
        $useCase->expects($this->never())->method('execute');

        $response = $this->app($useCase)->handle($this->request($code));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('codigo_invalido', json_decode((string) $response->getBody(), true)['error']);
    }

    public static function codigosInvalidos(): array
    {
        return [
            'corto' => ['AB2345678'],
            'largo' => ['AB234567890'],
            'con cero' => ['AB23456780'],
            'con letra O' => ['AB2345678O'],
            'simbolos' => ['AB2345678%25'],
        ];
    }

    public function test_codigo_inexistente_devuelve_404_generico(): void
    {
        $useCase = $this->createStub(GetTrackingUseCase::class);
        $useCase->method('execute')->willThrowException(new PackageNotFound('No encontramos un envio con ese codigo'));

        $response = $this->app($useCase)->handle($this->request('ZZZZZZZZZZ'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('envio_no_encontrado', json_decode((string) $response->getBody(), true)['error']);
    }

    public function test_al_superar_el_limite_responde_429_con_retry_after_sin_consultar(): void
    {
        $limiter = $this->createMock(RateLimiter::class);
        $limiter->expects($this->once())->method('hit')->with('tracking|198.51.100.9')
            ->willReturn(new RateLimitDecision(false, 30, 0, 17));
        $useCase = $this->createMock(GetTrackingUseCase::class);
        $useCase->expects($this->never())->method('execute');

        $response = $this->app($useCase, $limiter)->handle($this->request('AB23456789', '198.51.100.9'));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('17', $response->getHeaderLine('Retry-After'));
        self::assertSame('demasiadas_consultas', json_decode((string) $response->getBody(), true)['error']);
    }

    public function test_los_codigos_invalidos_tambien_cuentan_para_el_limite(): void
    {
        $limiter = $this->createMock(RateLimiter::class);
        $limiter->expects($this->once())->method('hit')->willReturn(new RateLimitDecision(true, 30, 10, 0));

        $response = $this->app($this->createStub(GetTrackingUseCase::class), $limiter)->handle($this->request('xx'));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_ignora_x_forwarded_for_para_no_saltarse_el_limite(): void
    {
        $limiter = $this->createMock(RateLimiter::class);
        $limiter->expects($this->once())->method('hit')->with('tracking|203.0.113.7')
            ->willReturn(new RateLimitDecision(true, 30, 5, 0));
        $useCase = $this->createStub(GetTrackingUseCase::class);
        $useCase->method('execute')->willReturn(new GetTrackingResult(['tracking_id' => 'AB23456789']));

        $request = $this->request('AB23456789')->withHeader('X-Forwarded-For', '10.0.0.1');
        self::assertSame(200, $this->app($useCase, $limiter)->handle($request)->getStatusCode());
    }
}
