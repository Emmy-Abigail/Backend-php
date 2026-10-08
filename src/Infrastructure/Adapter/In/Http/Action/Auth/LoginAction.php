<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Exception\InvalidCredentials;
use App\Application\Port\In\Auth\LoginCommand;
use App\Application\Port\In\Auth\LoginUseCase;
use DateTimeZone;
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
            return $this->json($response, [
                'message' => 'El cuerpo debe ser un objeto JSON válido',
                'error' => 'cuerpo_invalido',
            ], 400);
        }

        $email = $body['correo'] ?? null;
        $password = $body['password'] ?? null;

        if (!is_string($email) || !is_string($password)) {
            return $this->invalidCredentialsResponse($response);
        }

        $email = strtolower(trim($email));

        if ($email === '' || strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->invalidCredentialsResponse($response);
        }

        if ($password === '' || strlen($password) > 72) {
            return $this->invalidCredentialsResponse($response);
        }

        try {
            $result = $this->loginUseCase->execute(new LoginCommand($email, $password));
        } catch (InvalidCredentials) {
            return $this->invalidCredentialsResponse($response);
        }

        $sede = null;
        if ($result->role === 'OPERADOR' && $result->idSede !== null) {
            $sede = [
                'id' => $result->idSede,
                'nombre' => $result->sedeNombre ?? 'Sede Asignada',
            ];
        }

        $expiresAtLima = $result->expiresAt->setTimezone(new DateTimeZone('America/Lima'));

        return $this->json($response, [
            'token' => $result->token,
            'token_type' => $result->tokenType,
            'expires_at' => $expiresAtLima->format('Y-m-d\TH:i:sP'),
            'user' => [
                'id' => $result->id,
                'nombres' => $result->names,
                'correo' => $result->email,
                'rol' => $result->role,
                'debe_cambiar_password' => $result->mustChangePassword,
                'sede' => $sede,
            ],
        ]);
    }

    private function invalidCredentialsResponse(ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'message' => 'Credenciales incorrectas',
            'error' => 'credenciales_incorrectas',
        ], 401);
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