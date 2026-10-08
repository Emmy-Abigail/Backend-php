<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\User;

use App\Application\Exception\DniAlreadyExists;
use App\Application\Exception\EmailAlreadyExists;
use App\Application\Exception\InvalidCatalogReference;
use App\Application\Port\In\User\CreateUserCommand;
use App\Application\Port\In\User\CreateUserUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateUserAction
{
    private const ALLOWED_ROLES = ['OPERADOR', 'CONDUCTOR'];

    public function __construct(private CreateUserUseCase $createUserUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, ['message' => 'El cuerpo debe ser un objeto JSON válido', 'error' => 'cuerpo_invalido'], 400);
        }

        $names = $body['nombres'] ?? null;
        $dni = $body['dni'] ?? null;
        $email = $body['correo'] ?? null;
        $phone = $body['telefono'] ?? null;
        $role = $body['rol'] ?? null;
        $idSede = $body['id_sede'] ?? null;
        $idTipoVehiculo = $body['id_tipo_vehiculo'] ?? null;

        if (!is_string($names)) {
            return $this->json($response, ['message' => 'El campo nombres es obligatorio', 'error' => 'campo_obligatorio'], 422);
        }

        $names = trim($names);
        if ($names === '' || strlen($names) > 150) {
            return $this->json($response, ['message' => 'El campo nombres debe tener entre 1 y 150 caracteres', 'error' => 'campo_invalido'], 422);
        }

        if (!is_string($dni) || !preg_match('/^\d{8}$/', $dni)) {
            return $this->json($response, ['message' => 'El campo dni debe tener 8 dígitos', 'error' => 'campo_invalido'], 422);
        }

        if (!is_string($email)) {
            return $this->json($response, ['message' => 'El campo correo es obligatorio', 'error' => 'campo_obligatorio'], 422);
        }

        $email = strtolower(trim($email));

        if ($email === '' || strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->json($response, ['message' => 'El correo no tiene un formato válido', 'error' => 'campo_invalido'], 422);
        }

        if (!is_string($role) || !in_array($role, self::ALLOWED_ROLES, true)) {
            return $this->json($response, ['message' => 'El rol debe ser OPERADOR o CONDUCTOR', 'error' => 'rol_invalido'], 422);
        }

        if ($phone !== null && !is_string($phone)) {
            return $this->json($response, ['message' => 'El campo telefono no es válido', 'error' => 'campo_invalido'], 422);
        }

        if (is_string($phone)) {
            $phone = trim($phone);
            if ($phone === '') {
                $phone = null;
            } elseif (strlen($phone) > 20) {
                return $this->json($response, ['message' => 'El campo telefono no puede superar 20 caracteres', 'error' => 'campo_invalido'], 422);
            }
        }

        if ($role === 'OPERADOR') {
            if (!is_int($idSede)) {
                return $this->json($response, ['message' => 'El campo id_sede es obligatorio para el rol OPERADOR', 'error' => 'campo_obligatorio'], 422);
            }
            if ($idTipoVehiculo !== null) {
                return $this->json($response, ['message' => 'El campo id_tipo_vehiculo no aplica para el rol OPERADOR', 'error' => 'campo_invalido'], 422);
            }
        }

        if ($role === 'CONDUCTOR') {
            if (!is_int($idTipoVehiculo)) {
                return $this->json($response, ['message' => 'El campo id_tipo_vehiculo es obligatorio para el rol CONDUCTOR', 'error' => 'campo_obligatorio'], 422);
            }
            if ($idSede !== null) {
                return $this->json($response, ['message' => 'El campo id_sede no aplica para el rol CONDUCTOR', 'error' => 'campo_invalido'], 422);
            }
        }

        try {
            $result = $this->createUserUseCase->execute(
                new CreateUserCommand($names, $dni, $email, $phone, $role, $idSede, $idTipoVehiculo),
            );
        } catch (EmailAlreadyExists) {
            return $this->json($response, ['message' => 'Ya existe un usuario con ese correo', 'error' => 'correo_duplicado'], 409);
        } catch (DniAlreadyExists) {
            return $this->json($response, ['message' => 'Ya existe un usuario con ese DNI', 'error' => 'dni_duplicado'], 409);
        } catch (InvalidCatalogReference $exception) {
            return $this->json($response, ['message' => $exception->getMessage(), 'error' => 'referencia_invalida'], 422);
        }

        return $this->json($response, [
            'id' => $result->id,
            'nombres' => $result->names,
            'dni' => $result->dni,
            'correo' => $result->email,
            'rol' => $result->role,
            'id_sede' => $result->idSede,
            'id_tipo_vehiculo' => $result->idTipoVehiculo,
            'password_temporal' => $result->temporaryPassword,
            'correo_enviado' => $result->emailSent,
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