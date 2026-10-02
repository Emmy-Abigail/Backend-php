<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Exception\EmailDeliveryFailed;
use App\Application\Port\In\Auth\RequestPasswordResetCommand;
use App\Application\Port\In\Auth\RequestPasswordResetUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RequestPasswordResetAction
{
    public function __construct(private RequestPasswordResetUseCase $requestPasswordResetUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, ['message' => 'El cuerpo debe ser un objeto JSON válido'], 400);
        }

        $email = $body['correo'] ?? $body['email'] ?? null;

        if (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL) || mb_strlen(trim($email)) > 150) {
            return $this->json($response, ['message' => 'El campo correo debe contener un correo válido'], 422);
        }

        try {
            $this->requestPasswordResetUseCase->execute(
                new RequestPasswordResetCommand(mb_strtolower(trim($email)))
            );
        } catch (EmailDeliveryFailed) {
            // No se revela si una cuenta existe ni el estado de la entrega del correo.
        }

        return $this->json($response, [
            'message' => 'Si el correo está registrado, recibirás instrucciones para restablecer tu contraseña',
        ], 202);
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
