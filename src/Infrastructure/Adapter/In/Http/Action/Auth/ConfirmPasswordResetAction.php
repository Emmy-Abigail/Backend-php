<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Exception\InvalidPasswordResetToken;
use App\Application\Exception\SamePasswordException;
use App\Application\Exception\WeakPasswordException;
use App\Application\Port\In\Auth\ConfirmPasswordResetCommand;
use App\Application\Port\In\Auth\ConfirmPasswordResetUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ConfirmPasswordResetAction
{
    public function __construct(private ConfirmPasswordResetUseCase $confirmPasswordResetUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, ['message' => 'El cuerpo debe ser un objeto JSON válido', 'error' => 'cuerpo_invalido'], 400);
        }

        $token = $body['token'] ?? null;
        $newPassword = $body['password_nuevo'] ?? $body['nueva_password'] ?? $body['new_password'] ?? null;

        if (!is_string($token) || !preg_match('/\\A[a-f0-9]{64}\\z/i', $token)) {
            return $this->json($response, [
                'message' => 'El enlace no es válido o expiró',
                'error' => 'token_invalido',
            ], 400);
        }

        if (!is_string($newPassword) || $newPassword === '') {
            return $this->json($response, [
                'message' => 'El campo password_nuevo es obligatorio',
                'error' => 'campo_obligatorio',
            ], 422);
        }

        try {
            $this->confirmPasswordResetUseCase->execute(new ConfirmPasswordResetCommand(strtolower($token), $newPassword));
        } catch (InvalidPasswordResetToken) {
            return $this->json($response, [
                'message' => 'El enlace no es válido o expiró',
                'error' => 'token_invalido',
            ], 400);
        } catch (SamePasswordException) {
            return $this->json($response, [
                'message' => 'La nueva contraseña debe ser diferente a la actual',
                'error' => 'password_invalida',
            ], 422);
        } catch (WeakPasswordException) {
            return $this->json($response, [
                'message' => 'La nueva contraseña no cumple con la política de seguridad (mínimo 8 caracteres, una mayúscula, un número y un símbolo de @ # $ % &)',
                'error' => 'password_invalida',
            ], 422);
        }

        return $this->json($response, [
            'message' => 'Contraseña restablecida exitosamente',
        ], 200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}