<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\User;

use App\Application\Exception\EmailAlreadyExists;
use App\Application\Port\In\User\CreateUserCommand;
use App\Application\Port\In\User\CreateUserUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateUserAction
{
    private const ALLOWED_ROLES = ['Admin', 'Conductor'];

    public function __construct(private CreateUserUseCase $createUserUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, ['message' => 'El cuerpo debe ser un objeto JSON válido'], 400);
        }

        $names = $body['nombres'] ?? null;
        $email = $body['correo'] ?? null;
        $phone = $body['telefono'] ?? null;
        $role = $body['rol'] ?? null;

        if (!is_string($names)) {
            return $this->json($response, ['message' => 'El campo nombres es obligatorio'], 422);
        }

        $names = trim($names);
        if ($names === '' || strlen($names) > 150) {
            return $this->json($response, ['message' => 'El campo nombres debe tener entre 1 y 150 caracteres'], 422);
        }

        if (!is_string($email)) {
            return $this->json($response, ['message' => 'El campo correo es obligatorio'], 422);
        }

        $email = strtolower(trim($email));

        if ($email === '' || strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->json($response, ['message' => 'El correo no tiene un formato válido'], 422);
        }

        if (!is_string($role) || !in_array($role, self::ALLOWED_ROLES, true)) {
            return $this->json($response, ['message' => 'El rol debe ser Admin o Conductor'], 422);
        }

        if ($phone !== null && !is_string($phone)) {
            return $this->json($response, ['message' => 'El campo telefono no es válido'], 422);
        }

        if (is_string($phone)) {
            $phone = trim($phone);
            if ($phone === '') {
                $phone = null;
            } elseif (strlen($phone) > 20) {
                return $this->json($response, ['message' => 'El campo telefono no puede superar 20 caracteres'], 422);
            }
        }

        try {
            $result = $this->createUserUseCase->execute(
                new CreateUserCommand($names, $email, $phone, $role),
            );
        } catch (EmailAlreadyExists) {
            return $this->json($response, ['message' => 'Ya existe un usuario con ese correo'], 409);
        }

        return $this->json($response, [
            'id' => $result->id,
            'nombres' => $result->names,
            'correo' => $result->email,
            'rol' => $result->role,
            'password_temporal' => $result->temporaryPassword,
        ], 201);
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