<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Auth\ChangePasswordUseCase;
use App\Application\Port\In\Auth\ConfirmPasswordResetUseCase;
use App\Application\Port\In\Auth\LoginUseCase;
use App\Application\Port\In\Auth\RequestPasswordResetUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ChangePasswordAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ConfirmPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\LoginAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\MeAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\RequestPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\SessionStatusAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AuthRoutesTest extends TestCase
{
    #[Test]
    public function el_polling_de_sesion_responde_204_si_el_usuario_sigue_activo(): void
    {
        $authenticateToken = $this->createStub(AuthenticateTokenUseCase::class);
        $authenticateToken->method('execute')->willReturn(new AuthenticatedUser(1, 'Juan', 'juan@email.com', 'Conductor'));

        $response = $this->app($authenticateToken)->handle($this->sessionRequest('token-valido'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function el_polling_de_sesion_responde_401_si_el_usuario_fue_desactivado(): void
    {
        $authenticateToken = $this->createStub(AuthenticateTokenUseCase::class);
        $authenticateToken->method('execute')->willThrowException(new UserNotAllowed());

        $response = $this->app($authenticateToken)->handle($this->sessionRequest('token-vigente'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ['message' => 'Usuario inactivo o inexistente'],
            json_decode((string) $response->getBody(), true),
        );
    }

    #[Test]
    public function el_polling_de_sesion_responde_401_sin_token(): void
    {
        $authenticateToken = $this->createStub(AuthenticateTokenUseCase::class);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/auth/session');

        self::assertSame(401, $this->app($authenticateToken)->handle($request)->getStatusCode());
    }

    private function sessionRequest(string $token): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/auth/session')
            ->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function app(AuthenticateTokenUseCase $authenticateToken): \Slim\App
    {
        $app = AppFactory::create();
        $registerRoutes = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/AuthRoutes.php';
        $registerRoutes(
            $app,
            new LoginAction($this->createStub(LoginUseCase::class)),
            new MeAction(),
            new SessionStatusAction(),
            new ChangePasswordAction($this->createStub(ChangePasswordUseCase::class)),
            new RequestPasswordResetAction($this->createStub(RequestPasswordResetUseCase::class)),
            new ConfirmPasswordResetAction($this->createStub(ConfirmPasswordResetUseCase::class)),
            new JwtAuthMiddleware($authenticateToken),
        );

        return $app;
    }
}
