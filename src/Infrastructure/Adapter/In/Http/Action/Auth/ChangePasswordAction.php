<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Exception\InvalidCurrentPassword;
use App\Application\Exception\SamePasswordException;
use App\Application\Exception\UserNotAllowed;
use App\Application\Exception\WeakPasswordException;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Auth\ChangePasswordCommand;
use App\Application\Port\In\Auth\ChangePasswordUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ChangePasswordAction
{
    public function __construct(private ChangePasswordUseCase $changePasswordUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var AuthenticatedUser|null $authUser */
        $authUser = $request->getAttribute('authUser');

        if ($authUser === null) {
            return $this->json($response, ['message' => 'Token no proporcionado o inválido'], 401);
        }

        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, ['message' => 'El cuerpo debe ser un objeto JSON válido'], 400);
        }

        $currentPassword = $body['password_actual'] ?? $body['current_password'] ?? null;
        $newPassword = $body['password_nuevo'] ?? $body['nueva_password'] ?? $body['new_password'] ?? null;

        if (!is_string($currentPassword) || !is_string($newPassword) || $currentPassword === '' || $newPassword === '') {
            return $this->json($response, ['message' => 'Los campos password_actual y password_nuevo son obligatorios'], 400);
        }

        try {
            $this->changePasswordUseCase->execute(
                new ChangePasswordCommand($authUser->id, $currentPassword, $newPassword)
            );
        } catch (UserNotAllowed) {
            return $this->json($response, ['message' => 'Usuario inactivo o inexistente'], 401);
        } catch (InvalidCurrentPassword) {
            return $this->json($response, ['message' => 'La contraseña actual es incorrecta'], 400);
        } catch (SamePasswordException) {
            return $this->json($response, ['message' => 'La nueva contraseña debe ser diferente a la actual'], 422);
        } catch (WeakPasswordException) {
            return $this->json($response, [
                'message' => 'La nueva contraseña no cumple con la política de seguridad (mínimo 8 caracteres, mayúscula, minúscula, número y símbolo)',
            ], 422);
        }

        return $this->json($response, [
            'message' => 'Contraseña actualizada exitosamente',
        ], 200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
