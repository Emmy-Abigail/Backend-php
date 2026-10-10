<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Auth\ChangePasswordUseCase;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Port\Out\Security\IssuedToken;
use App\Application\Port\Out\Security\TokenService;
use App\Application\Service\Auth\ChangePasswordService;
use App\Domain\User\User;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ChangePasswordAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ChangePasswordActionTest extends TestCase
{
    #[Test]
    #[DataProvider('successfulRequests')]
    public function cambio_exitoso_depende_del_estado_en_bd_y_devuelve_solo_resultado(
        bool $mustChangePassword, array $body,
    ): void {
        $response = $this->changePassword($mustChangePassword, $body, true);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['message', 'token', 'token_type', 'expires_at'], array_keys($payload));
        self::assertSame('new-jwt', $payload['token']);
        self::assertSame('Bearer', $payload['token_type']);
        self::assertSame('2026-10-09T15:00:00-05:00', $payload['expires_at']);
    }

    public static function successfulRequests(): array
    {
        return [
            'primer cambio sin actual' => [true, ['password_nuevo' => 'NuevaClave2026@']],
            'primer cambio compatible con frontend' => [true, ['password_actual' => 'Temporal123!', 'password_nuevo' => 'NuevaClave2026@']],
            'primer cambio ignora tipo invalido' => [true, ['password_actual' => ['valor' => 'ignorado'], 'password_nuevo' => 'NuevaClave2026@']],
            'primer cambio ignora actual vacia' => [true, ['password_actual' => '', 'password_nuevo' => 'NuevaClave2026@']],
            'primer cambio ignora indicador falso del cliente' => [true, ['debe_cambiar_password' => false, 'password_nuevo' => 'NuevaClave2026@']],
            'primer cambio conserva alias' => [true, ['new_password' => 'NuevaClave2026@']],
            'normal con actual correcta' => [false, ['password_actual' => 'TemporalReal2026@', 'password_nuevo' => 'NuevaClave2026@']],
            'normal conserva alias' => [false, ['current_password' => 'TemporalReal2026@', 'nueva_password' => 'NuevaClave2026@']],
        ];
    }

    #[Test]
    #[DataProvider('rejectedRequests')]
    public function solicitudes_invalidas_no_actualizan_password_ni_emiten_jwt(
        bool $mustChangePassword, array $body, int $status, string $error,
    ): void {
        $response = $this->changePassword($mustChangePassword, $body, false);

        self::assertSame($status, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['message', 'error'], array_keys($payload));
        self::assertSame($error, $payload['error']);
    }

    public static function rejectedRequests(): array
    {
        return [
            'normal sin actual incluso con indicador cliente' => [false, ['debe_cambiar_password' => true, 'password_nuevo' => 'NuevaClave2026@'], 400, 'campos_obligatorios'],
            'normal actual vacia' => [false, ['password_actual' => '', 'password_nuevo' => 'NuevaClave2026@'], 400, 'campos_obligatorios'],
            'normal actual de tipo invalido' => [false, ['password_actual' => 123, 'password_nuevo' => 'NuevaClave2026@'], 400, 'campos_obligatorios'],
            'normal actual incorrecta' => [false, ['password_actual' => 'Temporal123!', 'password_nuevo' => 'NuevaClave2026@'], 400, 'password_actual_incorrecta'],
            'primer cambio debil' => [true, ['password_nuevo' => 'debil'], 422, 'password_invalida'],
            'primer cambio misma almacenada' => [true, ['password_actual' => 'Temporal123!', 'password_nuevo' => 'TemporalReal2026@'], 422, 'password_invalida'],
            'normal debil' => [false, ['password_actual' => 'TemporalReal2026@', 'password_nuevo' => 'debil'], 422, 'password_invalida'],
            'normal misma almacenada' => [false, ['password_actual' => 'TemporalReal2026@', 'password_nuevo' => 'TemporalReal2026@'], 422, 'password_invalida'],
        ];
    }

    #[Test]
    #[DataProvider('missingNewPasswords')]
    public function exige_password_nuevo_antes_de_invocar_el_servicio(array $body): void
    {
        $service = $this->createMock(ChangePasswordUseCase::class);
        $service->expects($this->never())->method('execute');
        $request = (new ServerRequestFactory())->createServerRequest('PATCH', '/api/v1/auth/change-password')
            ->withAttribute('authUser', new AuthenticatedUser(1, 'Juan', 'juan@test.com', 'CONDUCTOR', true))
            ->withParsedBody($body);

        $response = (new ChangePasswordAction($service))($request, (new ResponseFactory())->createResponse());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('campos_obligatorios', json_decode((string) $response->getBody(), true)['error']);
    }

    public static function missingNewPasswords(): array
    {
        return [
            'ausente' => [[]],
            'vacia' => [['password_nuevo' => '']],
            'null' => [['password_nuevo' => null]],
            'tipo invalido' => [['password_nuevo' => 123]],
        ];
    }

    #[Test]
    public function ruta_rechaza_solicitud_sin_jwt(): void
    {
        $service = $this->createMock(ChangePasswordUseCase::class);
        $service->expects($this->never())->method('execute');
        $authenticate = $this->createMock(AuthenticateTokenUseCase::class);
        $authenticate->expects($this->never())->method('execute');
        $app = AppFactory::create();
        $app->patch('/api/v1/auth/change-password', new ChangePasswordAction($service))
            ->add(new JwtAuthMiddleware($authenticate));
        $request = (new ServerRequestFactory())->createServerRequest('PATCH', '/api/v1/auth/change-password')
            ->withParsedBody(['password_nuevo' => 'NuevaClave2026@']);

        self::assertSame(401, $app->handle($request)->getStatusCode());
    }

    #[Test]
    #[DataProvider('unavailableUsers')]
    public function usuario_inexistente_o_inactivo_devuelve_401(?User $user): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())->method('findById')->with(1)->willReturn($user);
        $repository->expects($this->never())->method('updatePassword');
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($this->never())->method('issue');
        $request = (new ServerRequestFactory())->createServerRequest('PATCH', '/api/v1/auth/change-password')
            ->withAttribute('authUser', new AuthenticatedUser(1, 'Juan', 'juan@test.com', 'CONDUCTOR', true))
            ->withParsedBody(['password_nuevo' => 'NuevaClave2026@']);

        $response = (new ChangePasswordAction(new ChangePasswordService($repository, $tokens)))(
            $request, (new ResponseFactory())->createResponse(),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public static function unavailableUsers(): array
    {
        return [
            'inexistente' => [null],
            'inactivo' => [new User(1, 'Juan', 'juan@test.com', 'hash', 'CONDUCTOR', false, mustChangePassword: true)],
        ];
    }

    private function changePassword(bool $mustChangePassword, array $body, bool $success): ResponseInterface
    {
        $user = new User(1, 'Juan', 'juan@test.com', password_hash('TemporalReal2026@', PASSWORD_DEFAULT),
            'CONDUCTOR', true, mustChangePassword: $mustChangePassword);
        $updatedUser = new User(1, 'Juan', 'juan@test.com', password_hash('NuevaClave2026@', PASSWORD_DEFAULT),
            'CONDUCTOR', true, mustChangePassword: false,
            passwordChangedAt: new DateTimeImmutable('2026-10-09T12:00:00+00:00'));
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->exactly($success ? 2 : 1))->method('findById')->with(1)
            ->willReturnOnConsecutiveCalls($user, $updatedUser);
        $repository->expects($success ? $this->once() : $this->never())->method('updatePassword')
            ->with(1, $this->callback(static fn (string $hash): bool => password_verify('NuevaClave2026@', $hash)), false);
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects($success ? $this->once() : $this->never())->method('issue')->with($updatedUser)
            ->willReturn(new IssuedToken('new-jwt', new DateTimeImmutable('2026-10-09T20:00:00+00:00')));

        // Use a stale, opposite middleware flag: only the service's database read may authorize bypass.
        $request = (new ServerRequestFactory())->createServerRequest('PATCH', '/api/v1/auth/change-password')
            ->withAttribute('authUser', new AuthenticatedUser(1, 'Juan', 'juan@test.com', 'CONDUCTOR', !$mustChangePassword))
            ->withParsedBody($body);

        return (new ChangePasswordAction(new ChangePasswordService($repository, $tokens)))(
            $request, (new ResponseFactory())->createResponse(),
        );
    }
}
