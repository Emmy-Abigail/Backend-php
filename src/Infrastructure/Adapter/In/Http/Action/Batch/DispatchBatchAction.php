<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Batch;

use App\Application\Exception\BatchAccessDenied;
use App\Application\Exception\BatchAlreadyDispatched;
use App\Application\Exception\BatchHasNoPackages;
use App\Application\Exception\BatchNotFound;
use App\Application\Exception\BatchVersionMismatch;
use App\Application\Exception\BatchWeightExceeded;
use App\Application\Exception\DispatchSnapshotChanged;
use App\Application\Exception\DriverNotAvailable;
use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\RouteUnavailable;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Batch\DispatchBatchCommand;
use App\Application\Port\In\Batch\DispatchBatchUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class DispatchBatchAction
{
    public function __construct(private DispatchBatchUseCase $useCase)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $operator = $request->getAttribute('authUser');
        if (!$operator instanceof AuthenticatedUser) {
            return $this->json($response, ['error' => 'no_autenticado', 'message' => 'Token no proporcionado'], 401);
        }

        $rawId = $args['id'] ?? '';
        if (preg_match('/^[0-9]{1,18}$/', $rawId) !== 1 || (int) $rawId < 1) {
            return $this->json($response, ['error' => 'id_invalido', 'message' => 'El id del lote no es valido'], 422);
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            return $this->json($response, ['error' => 'cuerpo_invalido', 'message' => 'El cuerpo debe ser un objeto JSON valido'], 400);
        }

        foreach (['id_conductor', 'version'] as $field) {
            if (!array_key_exists($field, $body) || $body[$field] === null) {
                return $this->json($response, ['error' => 'campo_obligatorio', 'message' => sprintf('El campo %s es obligatorio', $field)], 422);
            }
        }
        if (!is_int($body['id_conductor']) || $body['id_conductor'] < 1) {
            return $this->json($response, ['error' => 'campo_invalido', 'message' => 'El campo id_conductor debe ser un entero positivo'], 422);
        }
        if (!is_int($body['version']) || $body['version'] < 0) {
            return $this->json($response, ['error' => 'campo_invalido', 'message' => 'El campo version debe ser un entero mayor o igual a 0'], 422);
        }

        try {
            $result = $this->useCase->execute(new DispatchBatchCommand($operator, (int) $rawId, $body['id_conductor'], $body['version']));
        } catch (BatchNotFound $exception) {
            return $this->json($response, ['error' => 'lote_no_encontrado', 'message' => $exception->getMessage()], 404);
        } catch (BatchAccessDenied $exception) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => $exception->getMessage()], 403);
        } catch (UserNotAllowed) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para despachar lotes'], 403);
        } catch (BatchAlreadyDispatched $exception) {
            return $this->json($response, ['error' => 'lote_ya_despachado', 'message' => $exception->getMessage()], 409);
        } catch (BatchVersionMismatch $exception) {
            return $this->json($response, ['error' => 'version_desactualizada', 'message' => $exception->getMessage()], 409);
        } catch (DispatchSnapshotChanged $exception) {
            return $this->json($response, ['error' => 'lote_cambio', 'message' => $exception->getMessage()], 409);
        } catch (BatchHasNoPackages $exception) {
            return $this->json($response, ['error' => 'lote_vacio', 'message' => $exception->getMessage()], 409);
        } catch (BatchWeightExceeded $exception) {
            return $this->json($response, ['error' => 'capacidad_excedida', 'message' => $exception->getMessage()], 409);
        } catch (DriverNotAvailable $exception) {
            return $this->json($response, ['error' => 'conductor_no_disponible', 'message' => $exception->getMessage()], 409);
        } catch (InvalidCatalogReference $exception) {
            return $this->json($response, ['error' => 'referencia_invalida', 'message' => $exception->getMessage()], 422);
        } catch (RouteUnavailable) {
            return $this->json($response, ['error' => 'ruta_no_disponible', 'message' => 'Servicio de rutas no disponible'], 503);
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
