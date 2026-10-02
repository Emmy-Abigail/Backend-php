<?php

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Exception\InvalidPasswordResetToken;
use App\Application\Port\In\Auth\ConfirmPasswordResetCommand;
use App\Application\Port\In\Auth\ConfirmPasswordResetUseCase;
use App\Application\Port\In\Auth\RequestPasswordResetCommand;
use App\Application\Port\In\Auth\RequestPasswordResetUseCase;
use App\Infrastructure\Adapter\In\Http\Action\Auth\ConfirmPasswordResetAction;
use App\Infrastructure\Adapter\In\Http\Action\Auth\RequestPasswordResetAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class PasswordResetActionTest extends TestCase
{
    private function request(string $path, mixed $body): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path);

        return $body === null ? $request : $request->withParsedBody($body);
    }

    #[Test]
    public function test_solicitud_valida_devuelve_202_y_normaliza_el_correo(): void
    {
        $useCase = $this->createMock(RequestPasswordResetUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (RequestPasswordResetCommand $command): bool => $command->email === 'ana@test.com'));

        $response = (new RequestPasswordResetAction($useCase))(
            $this->request('/auth/password-reset/request', ['correo' => ' ANA@TEST.COM ']),
            (new ResponseFactory())->createResponse(),
        );

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(
            ['message' => 'Si el correo está registrado, recibirás instrucciones para restablecer tu contraseña'],
            json_decode((string) $response->getBody(), true),
        );
    }

    #[Test]
    public function test_solicitud_con_correo_invalido_no_llama_al_caso_de_uso(): void
    {
        $useCase = $this->createMock(RequestPasswordResetUseCase::class);
        $useCase->expects($this->never())->method('execute');

        $response = (new RequestPasswordResetAction($useCase))(
            $this->request('/auth/password-reset/request', ['correo' => 'invalido']),
            (new ResponseFactory())->createResponse(),
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function test_confirmacion_acepta_token_hexadecimal_y_lo_normaliza(): void
    {
        $useCase = $this->createMock(ConfirmPasswordResetUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (ConfirmPasswordResetCommand $command): bool => $command->token === str_repeat('a', 64) && $command->newPassword === 'NuevaClave2026!'));

        $response = (new ConfirmPasswordResetAction($useCase))(
            $this->request('/auth/password-reset/confirm', ['token' => str_repeat('A', 64), 'password_nuevo' => 'NuevaClave2026!']),
            (new ResponseFactory())->createResponse(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function test_confirmacion_con_token_invalido_o_expirado_devuelve_422(): void
    {
        $useCase = $this->createMock(ConfirmPasswordResetUseCase::class);
        $useCase->method('execute')->willThrowException(new InvalidPasswordResetToken());
        $action = new ConfirmPasswordResetAction($useCase);

        $malformado = $action(
            $this->request('/auth/password-reset/confirm', ['token' => 'invalido', 'password_nuevo' => 'NuevaClave2026!']),
            (new ResponseFactory())->createResponse(),
        );
        $expirado = $action(
            $this->request('/auth/password-reset/confirm', ['token' => str_repeat('a', 64), 'password_nuevo' => 'NuevaClave2026!']),
            (new ResponseFactory())->createResponse(),
        );

        $this->assertSame(422, $malformado->getStatusCode());
        $this->assertSame(422, $expirado->getStatusCode());
    }
}
