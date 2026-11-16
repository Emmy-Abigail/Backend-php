<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Port\In\Auth\LoginResult;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;

/** Respuestas JSON comunes del login con contrasena y del login con Google. */
final class LoginResponse
{
    /** @return array<string, mixed> */
    public static function body(LoginResult $result): array
    {
        $sede = null;
        if ($result->role === 'OPERADOR' && $result->idSede !== null) {
            $sede = [
                'id' => $result->idSede,
                'nombre' => $result->sedeNombre ?? 'Sede Asignada',
            ];
        }

        $vehiculo = null;
        if ($result->role === 'CONDUCTOR' && $result->idTipoVehiculo !== null) {
            $vehiculo = [
                'id' => $result->idTipoVehiculo,
                'nombre' => $result->tipoVehiculoNombre ?? 'Vehículo Asignado',
                'peso_max_total_kg' => $result->pesoMaxTotalKg,
                'lado_max_cm' => $result->ladoMaxCm,
            ];
        }

        $expiresAtLima = $result->expiresAt->setTimezone(new DateTimeZone('America/Lima'));

        return [
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
                'vehiculo' => $vehiculo,
                'placa' => $result->placa,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
