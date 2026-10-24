<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Batch;

use App\Application\Exception\BatchAccessDenied;
use App\Application\Exception\BatchNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\GetBatchQuery;
use App\Application\Port\In\Batch\GetBatchUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class GetBatchAction
{
    public function __construct(private GetBatchUseCase $useCase)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $viewer = $request->getAttribute('authUser');
        if (!$viewer instanceof AuthenticatedUser) {
            return $this->json($response, ['error' => 'no_autenticado', 'message' => 'Token no proporcionado'], 401);
        }

        $rawId = $args['id'] ?? '';
        if (preg_match('/^[0-9]{1,18}$/', $rawId) !== 1 || (int) $rawId < 1) {
            return $this->json($response, ['error' => 'id_invalido', 'message' => 'El id del lote no es valido'], 422);
        }

        try {
            $result = $this->useCase->execute(new GetBatchQuery($viewer, (int) $rawId));
        } catch (BatchNotFound $exception) {
            return $this->json($response, ['error' => 'lote_no_encontrado', 'message' => $exception->getMessage()], 404);
        } catch (BatchAccessDenied $exception) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => $exception->getMessage()], 403);
        } catch (UserNotAllowed) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para consultar lotes'], 403);
        }

        return $this->json($response, $result->toArray(), 200);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
