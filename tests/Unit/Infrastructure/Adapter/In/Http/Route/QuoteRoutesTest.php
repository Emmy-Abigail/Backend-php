<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Pricing\QuoteShipmentResult;
use App\Application\Port\In\Pricing\QuoteShipmentUseCase;
use App\Domain\Pricing\Quote;
use App\Infrastructure\Adapter\In\Http\Action\Pricing\QuoteAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class QuoteRoutesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return [
            'ubigeo_destino' => '150140',
            'peso_kg' => 25,
            'largo_cm' => 12,
            'ancho_cm' => 30,
            'alto_cm' => 20,
        ];
    }

    #[Test]
    public function rechaza_cotizar_sin_token(): void
    {
        $app = $this->appForRole('OPERADOR');
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/quotes')->withParsedBody($this->body());

        self::assertSame(401, $app->handle($request)->getStatusCode());
    }

    #[Test]
    public function rechaza_a_un_administrador_aun_con_token_valido(): void
    {
        $response = $this->appForRole('ADMINISTRADOR')->handle($this->authorizedRequest());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function rechaza_a_un_conductor_aun_con_token_valido(): void
    {
        $response = $this->appForRole('CONDUCTOR')->handle($this->authorizedRequest());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function permite_a_un_operador_cotizar(): void
    {
        $response = $this->appForRole('OPERADOR')->handle($this->authorizedRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(93.75, json_decode((string) $response->getBody(), true)['monto']);
    }

    #[Test]
    public function exige_cambiar_la_contrasena_temporal_antes_de_cotizar(): void
    {
        $response = $this->appForRole('OPERADOR', true)->handle($this->authorizedRequest());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('cambio_password_obligatorio', json_decode((string) $response->getBody(), true)['error']);
    }

    private function authorizedRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/quotes')
            ->withHeader('Authorization', 'Bearer token-valido')
            ->withParsedBody($this->body());
    }

    private function appForRole(string $role, bool $mustChangePassword = false): App
    {
        $useCase = $this->createStub(QuoteShipmentUseCase::class);
        $useCase->method('execute')->willReturn(
            new QuoteShipmentResult(new Quote(144, 2500, 15, 250, 9375, 1, 3), 1, 'Sede Norte', 'NORTE', 'SUR'),
        );

        $authenticateToken = $this->createStub(AuthenticateTokenUseCase::class);
        $authenticateToken->method('execute')->willReturn(
            new AuthenticatedUser(1, 'Ana Torres', 'ana@email.com', $role, $mustChangePassword, 1, 'Sede Norte'),
        );

        $app = AppFactory::create();
        $registerRoutes = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/QuoteRoutes.php';
        $registerRoutes(
            $app,
            new QuoteAction($useCase),
            new JwtAuthMiddleware($authenticateToken),
            new RoleMiddleware('OPERADOR'),
            new MustChangePasswordMiddleware(),
        );

        return $app;
    }
}
