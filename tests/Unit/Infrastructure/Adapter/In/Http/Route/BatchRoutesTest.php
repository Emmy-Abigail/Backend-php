<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Route;

use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\BatchPage;
use App\Application\Port\In\Batch\DispatchBatchUseCase;
use App\Application\Port\In\Batch\GetBatchResult;
use App\Application\Port\In\Batch\GetBatchUseCase;
use App\Application\Port\In\Batch\ListBatchesUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Batch\DispatchBatchAction;
use App\Infrastructure\Adapter\In\Http\Action\Batch\GetBatchAction;
use App\Infrastructure\Adapter\In\Http\Action\Batch\ListBatchesAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\BatchFixture;

require_once __DIR__ . '/../../../../../../Support/BatchFixture.php';

final class BatchRoutesTest extends TestCase
{
    private function request(string $method, string $path, bool $authorized = true, mixed $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($authorized) {
            $request = $request->withHeader('Authorization', 'Bearer token-valido');
        }

        return $body === null ? $request : $request->withParsedBody($body);
    }

    private function handle(App $app, ServerRequestInterface $request): ResponseInterface
    {
        return $app->handle($request);
    }

    #[Test]
    public function rechaza_las_consultas_y_el_despacho_sin_token(): void
    {
        $app = $this->appForRole('OPERADOR');

        self::assertSame(401, $this->handle($app, $this->request('GET', '/api/v1/batches', false))->getStatusCode());
        self::assertSame(401, $this->handle($app, $this->request('GET', '/api/v1/batches/210', false))->getStatusCode());
        self::assertSame(401, $this->handle($app, $this->request('POST', '/api/v1/batches/210/dispatch', false, ['id_conductor' => 31, 'version' => 0]))->getStatusCode());
    }

    #[Test]
    public function el_operador_y_el_conductor_pueden_listar_lotes(): void
    {
        foreach (['OPERADOR', 'CONDUCTOR'] as $role) {
            $response = $this->handle($this->appForRole($role), $this->request('GET', '/api/v1/batches'));

            self::assertSame(200, $response->getStatusCode(), $role);
            self::assertSame('L-ABC123', json_decode((string) $response->getBody(), true)['data'][0]['codigo']);
        }
    }

    #[Test]
    public function el_operador_y_el_conductor_pueden_ver_el_detalle(): void
    {
        foreach (['OPERADOR', 'CONDUCTOR'] as $role) {
            $response = $this->handle($this->appForRole($role), $this->request('GET', '/api/v1/batches/210'));

            self::assertSame(200, $response->getStatusCode(), $role);
        }
    }

    #[Test]
    public function el_administrador_no_puede_consultar_lotes(): void
    {
        $app = $this->appForRole('ADMINISTRADOR');

        self::assertSame(403, $this->handle($app, $this->request('GET', '/api/v1/batches'))->getStatusCode());
        self::assertSame(403, $this->handle($app, $this->request('GET', '/api/v1/batches/210'))->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rolesWithoutDispatch(): array
    {
        return ['conductor' => ['CONDUCTOR'], 'administrador' => ['ADMINISTRADOR']];
    }

    #[Test]
    #[DataProvider('rolesWithoutDispatch')]
    public function solo_el_operador_puede_despachar(string $role): void
    {
        $response = $this->handle(
            $this->appForRole($role),
            $this->request('POST', '/api/v1/batches/210/dispatch', true, ['id_conductor' => 31, 'version' => 0]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function el_operador_despacha_un_lote(): void
    {
        $response = $this->handle(
            $this->appForRole('OPERADOR'),
            $this->request('POST', '/api/v1/batches/210/dispatch', true, ['id_conductor' => 31, 'version' => 0]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ASIGNADO', json_decode((string) $response->getBody(), true)['estado']);
    }

    #[Test]
    public function exige_cambiar_la_contrasena_temporal_antes_de_consultar_o_despachar(): void
    {
        $app = $this->appForRole('OPERADOR', true);

        foreach ([
            $this->request('GET', '/api/v1/batches'),
            $this->request('GET', '/api/v1/batches/210'),
            $this->request('POST', '/api/v1/batches/210/dispatch', true, ['id_conductor' => 31, 'version' => 0]),
        ] as $request) {
            $response = $this->handle($app, $request);

            self::assertSame(403, $response->getStatusCode());
            self::assertSame('cambio_password_obligatorio', json_decode((string) $response->getBody(), true)['error']);
        }
    }

    #[Test]
    public function las_rutas_obsoletas_siguen_sin_implementar(): void
    {
        $app = $this->appForRole('OPERADOR');

        self::assertSame(501, $this->handle($app, $this->request('POST', '/api/v1/batches', false))->getStatusCode());
        self::assertSame(501, $this->handle($app, $this->request('PATCH', '/api/v1/batches/210/assignment', false))->getStatusCode());
    }

    private function appForRole(string $role, bool $mustChangePassword = false): App
    {
        $list = $this->createStub(ListBatchesUseCase::class);
        $list->method('execute')->willReturn(new BatchPage([BatchFixture::summary()], 1, 20, 1));

        $get = $this->createStub(GetBatchUseCase::class);
        $get->method('execute')->willReturn(new GetBatchResult(BatchFixture::summary(), [BatchFixture::package(1)], [], 'OPERADOR'));

        $dispatch = $this->createStub(DispatchBatchUseCase::class);
        $dispatch->method('execute')->willReturn(BatchFixture::dispatchResult());

        $authenticateToken = $this->createStub(AuthenticateTokenUseCase::class);
        $authenticateToken->method('execute')->willReturn(
            new AuthenticatedUser(5, 'Usuario', 'usuario@email.com', $role, $mustChangePassword, 1, 'Sede Norte'),
        );

        $app = AppFactory::create();
        $registerRoutes = require __DIR__ . '/../../../../../../../src/Infrastructure/Adapter/In/Http/Route/BatchRoutes.php';
        $registerRoutes(
            $app,
            new ListBatchesAction($list),
            new GetBatchAction($get),
            new DispatchBatchAction($dispatch),
            new JwtAuthMiddleware($authenticateToken),
            new RoleMiddleware('OPERADOR', 'CONDUCTOR'),
            new RoleMiddleware('OPERADOR'),
            new MustChangePasswordMiddleware(),
        );

        return $app;
    }
}
