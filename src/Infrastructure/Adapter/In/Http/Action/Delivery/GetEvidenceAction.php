<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Delivery;

use App\Application\Exception\EvidenceNotFound;
use App\Application\Exception\EvidenceStorageUnavailable;
use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Delivery\GetEvidenceQuery;
use App\Application\Port\In\Delivery\GetEvidenceUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\StreamFactory;

final readonly class GetEvidenceAction
{
    public function __construct(private GetEvidenceUseCase $useCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $viewer = $request->getAttribute('authUser');
        if (!$viewer instanceof AuthenticatedUser) {
            return $this->json($response, 401, 'no_autenticado', 'Token no proporcionado');
        }
        if ($viewer->mustChangePassword || !in_array($viewer->role, ['OPERADOR', 'ADMINISTRADOR'], true)) {
            return $this->json($response, 403, 'sin_permisos', 'No tiene permisos para consultar evidencias');
        }
        $rawId = $args['id'] ?? '';
        if (!is_string($rawId) || preg_match('/^[0-9]{1,18}$/D', $rawId) !== 1 || (int) $rawId < 1) {
            return $this->json($response, 422, 'id_invalido', 'El id del paquete no es valido');
        }
        try {
            $file = $this->useCase->execute(new GetEvidenceQuery($viewer, (int) $rawId));
            $extension = match ($file->mediaType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                default => throw new EvidenceNotFound(),
            };
            return $response->withStatus(200)
                ->withBody((new StreamFactory())->createStream($file->bytes))
                ->withHeader('Content-Type', $file->mediaType)
                ->withHeader('Content-Length', (string) strlen($file->bytes))
                ->withHeader('Content-Disposition', 'inline; filename="evidencia-' . (int) $rawId . '.' . $extension . '"');
        } catch (PackageNotFound) {
            return $this->json($response, 404, 'paquete_no_encontrado', 'El paquete no existe');
        } catch (EvidenceNotFound) {
            return $this->json($response, 404, 'evidencia_no_encontrada', 'No hay evidencia disponible para este paquete');
        } catch (PackageAccessDenied | UserNotAllowed) {
            return $this->json($response, 403, 'sin_permisos', 'No tiene permisos para consultar esta evidencia');
        } catch (EvidenceStorageUnavailable) {
            return $this->json($response, 503, 'evidencia_no_disponible', 'No se pudo leer la evidencia; vuelve a intentarlo');
        } catch (\Throwable) {
            error_log('Fallo al consultar la evidencia de un paquete');
            return $this->json($response, 500, 'error_interno', 'No se pudo consultar la evidencia');
        }
    }

    private function json(ResponseInterface $response, int $status, string $error, string $message): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => $error, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
