<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Exception\GoogleAccountNotRegistered;
use App\Application\Exception\InvalidCredentials;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Auth\ChangePasswordUseCase;
use App\Application\Port\In\Auth\ConfirmPasswordResetUseCase;
use App\Application\Port\In\Auth\GoogleLoginUseCase;
use App\Application\Port\In\Auth\LoginUseCase;
use App\Application\Port\In\Auth\RequestPasswordResetUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ChangePasswordAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ConfirmPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\GoogleLoginAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\LoginAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\MeAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\RequestPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\SessionStatusAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RateLimitMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\WarmUpServicesMiddleware;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use App\Infrastructure\Adapter\Out\Security\FileRateLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AuthRoutesTest extends TestCase
{
    private string $limitDir = '';

    protected function tearDown(): void
    {
        if ($this->limitDir !== '') {
            foreach (glob($this->limitDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->limitDir);
        }
    }

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

    #[Test]
    public function la_ruta_de_google_existe_solo_si_esta_configurada(): void
    {
        $googleLogin = $this->createStub(GoogleLoginUseCase::class);
        $googleLogin->method('execute')->willThrowException(new GoogleAccountNotRegistered());
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/auth/google')
            ->withParsedBody(['id_token' => 'id-token']);

        $withGoogle = $this->app($this->createStub(AuthenticateTokenUseCase::class), new GoogleLoginAction($googleLogin));
        $response = $withGoogle->handle($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('cuenta_no_registrada', json_decode((string) $response->getBody(), true)['error']);

        $withoutGoogle = $this->app($this->createStub(AuthenticateTokenUseCase::class));
        $this->expectException(HttpNotFoundException::class);
        $withoutGoogle->handle($request);
    }

    #[Test]
    public function el_login_se_limita_por_correo_sin_bloquear_a_otros_usuarios(): void
    {
        $login = $this->createStub(LoginUseCase::class);
        $login->method('execute')->willThrowException(new InvalidCredentials());
        // Igual que en produccion: Google configurado y aviso a Python activo (con ambos, hay dos rutas de login).
        $app = $this->app($this->createStub(AuthenticateTokenUseCase::class), $this->googleAction(), $this->loginRateLimit(2), $login, $this->warmUp());

        self::assertSame(401, $this->loginAttempt($app, 'Juan@Email.com')->getStatusCode());
        self::assertSame(401, $this->loginAttempt($app, 'juan@email.com')->getStatusCode());

        $blocked = $this->loginAttempt($app, 'JUAN@EMAIL.COM');
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('demasiadas_consultas', json_decode((string) $blocked->getBody(), true)['error']);
        self::assertNotSame('', $blocked->getHeaderLine('Retry-After'));

        // Otro correo desde la misma IP sigue pudiendo intentar.
        self::assertSame(401, $this->loginAttempt($app, 'otra@email.com')->getStatusCode());
    }

    #[Test]
    public function el_login_con_google_no_tiene_limite_de_intentos(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/auth/google')
            ->withParsedBody(['id_token' => 'id-token']);
        $app = $this->app($this->createStub(AuthenticateTokenUseCase::class), $this->googleAction(), $this->loginRateLimit(1), null, $this->warmUp());

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(401, $app->handle($request)->getStatusCode());
        }
    }

    private function googleAction(): GoogleLoginAction
    {
        $googleLogin = $this->createStub(GoogleLoginUseCase::class);
        $googleLogin->method('execute')->willThrowException(new GoogleAccountNotRegistered());

        return new GoogleLoginAction($googleLogin);
    }

    private function warmUp(): WarmUpServicesMiddleware
    {
        return new WarmUpServicesMiddleware($this->createStub(HttpTransport::class), []);
    }

    private function loginRateLimit(int $perMinute): RateLimitMiddleware
    {
        $this->limitDir = sys_get_temp_dir() . '/riva-login-limit-test-' . bin2hex(random_bytes(4));

        return RateLimitMiddleware::perBodyField(new FileRateLimiter($this->limitDir, $perMinute), 'login', 'correo');
    }

    private function loginAttempt(\Slim\App $app, string $email): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/v1/auth/login')
                ->withParsedBody(['correo' => $email, 'password' => 'clave-incorrecta']),
        );
    }

    private function sessionRequest(string $token): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/auth/session')
            ->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function app(
        AuthenticateTokenUseCase $authenticateToken,
        ?GoogleLoginAction $googleLoginAction = null,
        ?RateLimitMiddleware $loginRateLimit = null,
        ?LoginUseCase $login = null,
        ?WarmUpServicesMiddleware $warmUp = null,
    ): \Slim\App
    {
        $app = AppFactory::create();
        $registerRoutes = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/AuthRoutes.php';
        $registerRoutes(
            $app,
            new LoginAction($login ?? $this->createStub(LoginUseCase::class)),
            new MeAction(),
            new SessionStatusAction(),
            new ChangePasswordAction($this->createStub(ChangePasswordUseCase::class)),
            new RequestPasswordResetAction($this->createStub(RequestPasswordResetUseCase::class)),
            new ConfirmPasswordResetAction($this->createStub(ConfirmPasswordResetUseCase::class)),
            new JwtAuthMiddleware($authenticateToken),
            $warmUp,
            $googleLoginAction,
            $loginRateLimit,
        );

        return $app;
    }
}
