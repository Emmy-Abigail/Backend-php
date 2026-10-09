<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\User;

use App\Application\Port\In\User\ListUsersResult;
use App\Application\Port\In\User\ListUsersUseCase;
use App\Application\Port\In\User\UserListItem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListUsersAction
{
    private const ALLOWED_ROLES = ['ADMINISTRADOR', 'OPERADOR', 'CONDUCTOR'];

    public function __construct(private ListUsersUseCase $listUsersUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $queryParams = $request->getQueryParams();

        $role = $queryParams['rol'] ?? null;
        if ($role !== null && !in_array($role, self::ALLOWED_ROLES, true)) {
            return $this->json($response, ['message' => 'El filtro rol no es válido', 'error' => 'filtro_invalido'], 422);
        }

        $idSede = null;
        if (isset($queryParams['sede']) && $queryParams['sede'] !== '') {
            if (!is_numeric($queryParams['sede'])) {
                return $this->json($response, ['message' => 'El filtro sede debe ser numérico', 'error' => 'filtro_invalido'], 422);
            }
            $idSede = (int) $queryParams['sede'];
        }

        $result = $this->listUsersUseCase->execute($role, $idSede);

        return $this->jsonResult($response, $result);
    }

    private function jsonResult(ResponseInterface $response, ListUsersResult $result): ResponseInterface
    {
        $response->getBody()->write(json_encode([
            'data' => array_map(
                static fn (UserListItem $user): array => [
                    'id' => $user->id,
                    'nombres' => $user->names,
                    'correo' => $user->email,
                    'dni' => $user->dni,
                    'telefono' => $user->phone,
                    'rol' => $user->role,
                    'activo' => $user->active,
                    'id_sede' => $user->idSede,
                    'id_tipo_vehiculo' => $user->idTipoVehiculo,
                    'sede_nombre' => $user->sedeNombre,
                    'tipo_vehiculo' => $user->tipoVehiculo,
                ],
                $result->users,
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
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