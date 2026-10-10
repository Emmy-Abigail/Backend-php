<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Auth;

use App\Application\Port\In\Auth\AuthenticatedUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class MeAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var AuthenticatedUser $authUser */
        $authUser = $request->getAttribute('authUser');

        $sede = null;
        if ($authUser->role === 'OPERADOR' && $authUser->idSede !== null) {
            $sede = [
                'id' => $authUser->idSede,
                'nombre' => $authUser->sedeNombre ?? 'Sede Asignada',
            ];
        }

        $vehiculo = null;
        if ($authUser->role === 'CONDUCTOR' && $authUser->idTipoVehiculo !== null) {
            $vehiculo = [
                'id' => $authUser->idTipoVehiculo,
                'nombre' => $authUser->tipoVehiculoNombre ?? 'Vehículo Asignado',
                'peso_max_total_kg' => $authUser->pesoMaxTotalKg,
                'lado_max_cm' => $authUser->ladoMaxCm,
            ];
        }

        return $this->json($response, [
            'user' => [
                'id' => $authUser->id,
                'nombres' => $authUser->names,
                'correo' => $authUser->email,
                'rol' => $authUser->role,
                'debe_cambiar_password' => $authUser->mustChangePassword,
                'sede' => $sede,
                'vehiculo' => $vehiculo,
                'placa' => $authUser->placa,
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