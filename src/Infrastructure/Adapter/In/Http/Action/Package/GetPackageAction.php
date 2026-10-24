<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Package;

use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\GetPackageQuery;
use App\Application\Port\In\Package\GetPackageUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class GetPackageAction
{
    public function __construct(private GetPackageUseCase $useCase)
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
            return $this->json($response, ['error' => 'id_invalido', 'message' => 'El id del paquete no es valido'], 422);
        }

        try {
            $result = $this->useCase->execute(new GetPackageQuery($viewer, (int) $rawId));
        } catch (PackageNotFound $exception) {
            return $this->json($response, ['error' => 'paquete_no_encontrado', 'message' => $exception->getMessage()], 404);
        } catch (PackageAccessDenied $exception) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => $exception->getMessage()], 403);
        } catch (UserNotAllowed) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para consultar paquetes'], 403);
        }

        return $this->json($response, $result->toArray(), 200);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
