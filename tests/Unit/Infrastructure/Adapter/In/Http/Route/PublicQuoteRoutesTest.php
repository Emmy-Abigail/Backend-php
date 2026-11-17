<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Catalog\GetGeographyUseCase;
use App\Application\Port\In\Catalog\GetSedesUseCase;
use App\Application\Port\In\Pricing\QuoteShipmentResult;
use App\Application\Port\In\Pricing\QuoteShipmentUseCase;
use App\Domain\Catalog\Sede;
use App\Domain\Pricing\Quote;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetGeographyAction;
use App\Infrastructure\Adapter\In\Http\Action\Catalog\GetPublicSedesAction;
use App\Infrastructure\Adapter\In\Http\Action\Pricing\PublicQuoteAction;
use App\Infrastructure\Adapter\In\Http\Action\Pricing\QuoteAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RateLimitMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use App\Infrastructure\Adapter\Out\Security\FileRateLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PublicQuoteRoutesTest extends TestCase
{
    private string $limitDir;

    protected function setUp(): void
    {
        $this->limitDir = sys_get_temp_dir() . '/riva-public-quote-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->limitDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->limitDir);
    }

    private function app(int $limitPerMinute = 100): App
    {
        $quote = $this->createStub(QuoteShipmentUseCase::class);
        $quote->method('execute')->willReturn(
            new QuoteShipmentResult(new Quote(144, 2500, 15, 250, 9375, 1, 3), 1, 'Sede Norte', 'NORTE', 'SUR'),
        );

        $sedes = $this->createStub(GetSedesUseCase::class);
        $sedes->method('execute')->willReturn([
            new Sede(1, 'Sede Norte', 1, 'NORTE', 'Norte', '150117', 'Los Olivos', 'Av. Uno', -11.99, -77.07, true),
            new Sede(2, 'Sede Cerrada', 2, 'CENTRO', 'Centro', '150113', 'Jesús María', 'Av. Dos', -12.07, -77.04, false),
        ]);

        $geography = $this->createStub(GetGeographyUseCase::class);
        $geography->method('execute')->willReturn([]);

        $authenticateToken = $this->createStub(AuthenticateTokenUseCase::class);

        $app = AppFactory::create();
        (require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/PublicQuoteRoutes.php')(
            $app,
            new PublicQuoteAction($quote, $sedes),
            new GetPublicSedesAction($sedes),
            new GetGeographyAction($geography),
            new RateLimitMiddleware(new FileRateLimiter($this->limitDir, $limitPerMinute), 'public'),
        );
        // La cotización interna convive con la pública y sigue protegida.
        (require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/QuoteRoutes.php')(
            $app,
            new QuoteAction($quote),
            new JwtAuthMiddleware($authenticateToken),
            new RoleMiddleware('OPERADOR'),
            new MustChangePasswordMiddleware(),
        );

        return $app;
    }

    private function post(App $app, string $path, array $body): ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest('POST', $path)->withParsedBody($body));
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return ['id_sede' => 1, 'ubigeo_destino' => '150140', 'peso_kg' => 25, 'largo_cm' => 12, 'ancho_cm' => 30, 'alto_cm' => 20];
    }

    #[Test]
    public function cotiza_sin_token_en_la_ruta_publica(): void
    {
        $response = $this->post($this->app(), '/api/v1/public/quotes', $this->body());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(93.75, json_decode((string) $response->getBody(), true)['monto']);
    }

    #[Test]
    public function la_cotizacion_interna_sigue_exigiendo_token(): void
    {
        $response = $this->post($this->app(), '/api/v1/quotes', $this->body());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function las_sedes_publicas_solo_incluyen_las_activas(): void
    {
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/public/sedes'));

        self::assertSame(200, $response->getStatusCode());
        $ids = array_column(json_decode((string) $response->getBody(), true)['data'], 'id');
        self::assertSame([1], $ids);
    }

    #[Test]
    public function la_geografia_publica_responde_sin_token(): void
    {
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/public/geography'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function responde_429_al_superar_el_limite_por_minuto(): void
    {
        $app = $this->app(2);

        self::assertSame(200, $this->post($app, '/api/v1/public/quotes', $this->body())->getStatusCode());
        self::assertSame(200, $this->post($app, '/api/v1/public/quotes', $this->body())->getStatusCode());

        $blocked = $this->post($app, '/api/v1/public/quotes', $this->body());
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('demasiadas_consultas', json_decode((string) $blocked->getBody(), true)['error']);
    }
}
