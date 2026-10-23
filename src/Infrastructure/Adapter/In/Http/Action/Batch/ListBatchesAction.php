<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Batch;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\ListBatchesQuery;
use App\Application\Port\In\Batch\ListBatchesUseCase;
use App\Domain\Batch\BatchStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListBatchesAction
{
    private const DEFAULT_SIZE = 20;
    private const MAX_SIZE = 100;

    public function __construct(private ListBatchesUseCase $useCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $viewer = $request->getAttribute('authUser');
        if (!$viewer instanceof AuthenticatedUser) {
            return $this->json($response, ['error' => 'no_autenticado', 'message' => 'Token no proporcionado'], 401);
        }

        $params = $request->getQueryParams();

        $status = null;
        $rawStatus = $params['estado'] ?? null;
        if ($rawStatus !== null && $rawStatus !== '') {
            $status = is_string($rawStatus) ? BatchStatus::tryFrom($rawStatus) : null;
            if ($status === null) {
                return $this->json($response, [
                    'error' => 'filtro_invalido',
                    'message' => 'El filtro estado debe ser EN_PREPARACION, ASIGNADO, EN_RUTA o CERRADO',
                ], 422);
            }
        }

        $page = $this->positiveInt($params['page'] ?? null, 1, null);
        $size = $this->positiveInt($params['size'] ?? null, self::DEFAULT_SIZE, self::MAX_SIZE);
        if ($page === null || $size === null) {
            return $this->json($response, [
                'error' => 'filtro_invalido',
                'message' => sprintf('Los parametros page y size deben ser enteros positivos (size maximo %d)', self::MAX_SIZE),
            ], 422);
        }

        try {
            $result = $this->useCase->execute(new ListBatchesQuery($viewer, $status, $page, $size));
        } catch (UserNotAllowed) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para consultar lotes'], 403);
        }

        return $this->json($response, $result->toArray(), 200);
    }

    /** Devuelve el valor por defecto si no viene, o null si no es un entero válido. */
    private function positiveInt(mixed $raw, int $default, ?int $max): ?int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_string($raw) || preg_match('/^[0-9]{1,9}$/', $raw) !== 1) {
            return null;
        }

        $value = (int) $raw;

        return $value >= 1 && ($max === null || $value <= $max) ? $value : null;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
