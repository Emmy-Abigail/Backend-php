<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Exception\InvalidCredentials;
use App\Application\Port\In\Auth\LoginCommand;
use App\Application\Port\In\Auth\LoginUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class LoginAction
{
    public function __construct(private LoginUseCase $loginUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, ['message' => 'El cuerpo debe ser un objeto JSON válido'], 400);
        }

        $email = $body['correo'] ?? null;
        $password = $body['password'] ?? null;

        if (!is_string($email) || !is_string($password)) {
            return $this->json($response, ['message' => 'Los campos correo y password son obligatorios'], 400);
        }

        $email = strtolower(trim($email));

        if ($email === '' || strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->json($response, ['message' => 'El correo no tiene un formato válido'], 422);
        }

        if ($password === '' || strlen($password) > 72) {
            return $this->json($response, ['message' => 'La contraseña debe tener entre 1 y 72 caracteres'], 422);
        }

        try {
            $result = $this->loginUseCase->execute(new LoginCommand($email, $password));
        } catch (InvalidCredentials) {
            return $this->json($response, ['message' => 'Credenciales inválidas'], 401);
        }

        return $this->json($response, [
            'user' => [
                'id' => $result->id,
                'nombres' => $result->names,
                'correo' => $result->email,
                'rol' => $result->role,
            ],
        ]);
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
