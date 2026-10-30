<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Package;

use App\Application\Exception\AssignmentUnavailable;
use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageAlreadyRetried;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\PackageNotRetryable;
use App\Application\Exception\PackageRegistrationConflict;
use App\Application\Exception\PricingUnavailable;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\RetryPackageCommand;
use App\Application\Port\In\Package\RetryPackageUseCase;
use App\Domain\Package\InvalidPackageData;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RetryPackageAction
{
    public function __construct(private RetryPackageUseCase $useCase)
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
        if (preg_match('/^[0-9]{1,18}$/D', $rawId) !== 1 || (int) $rawId < 1) {
            return $this->json($response, ['error' => 'id_invalido', 'message' => 'El id del paquete no es valido'], 422);
        }

        // El cuerpo es opcional: sin cuerpo se copia todo del paquete fallido.
        $body = $request->getParsedBody() ?? [];
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            return $this->json($response, ['error' => 'cuerpo_invalido', 'message' => 'El cuerpo debe ser un objeto JSON valido'], 400);
        }
        $errors = $this->correctionErrors($body);
        if ($errors !== []) {
            return $this->json($response, ['error' => 'datos_invalidos', 'message' => 'Solo se puede corregir la direccion y el celular del destinatario', 'campos' => $errors], 422);
        }

        try {
            $result = $this->useCase->execute(new RetryPackageCommand($operator, (int) $rawId, $body));
        } catch (PackageNotFound $exception) {
            return $this->json($response, ['error' => 'paquete_no_encontrado', 'message' => $exception->getMessage()], 404);
        } catch (PackageAccessDenied $exception) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => $exception->getMessage()], 403);
        } catch (PackageNotRetryable $exception) {
            return $this->json($response, ['error' => 'reintento_no_permitido', 'message' => $exception->getMessage()], 409);
        } catch (PackageAlreadyRetried $exception) {
            return $this->json($response, ['error' => 'reintento_existente', 'message' => $exception->getMessage()], 409);
        } catch (InvalidPackageData $exception) {
            return $this->json($response, ['error' => 'datos_invalidos', 'message' => $exception->getMessage(), 'campos' => $exception->errors], 422);
        } catch (InvalidCatalogReference $exception) {
            return $this->json($response, ['error' => 'referencia_invalida', 'message' => $exception->getMessage()], 422);
        } catch (PricingUnavailable) {
            return $this->json($response, ['error' => 'cotizacion_no_disponible', 'message' => 'Servicio de cotizacion no disponible'], 503);
        } catch (AssignmentUnavailable) {
            return $this->json($response, ['error' => 'asignacion_no_disponible', 'message' => 'Servicio de asignacion no disponible'], 503);
        } catch (PackageRegistrationConflict $exception) {
            return $this->json($response, ['error' => 'registro_en_conflicto', 'message' => $exception->getMessage()], 409);
        } catch (UserNotAllowed) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para reintentar paquetes'], 403);
        }

        return $this->json($response, $result->toArray() + ['id_paquete_origen' => (int) $rawId], 201);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function correctionErrors(array $body): array
    {
        $errors = [];
        foreach (array_diff(array_keys($body), ['direccion', 'destinatario']) as $field) {
            $errors[(string) $field] = 'Campo no permitido en un reintento';
        }
        foreach (['direccion', 'destinatario'] as $group) {
            if (array_key_exists($group, $body) && (!is_array($body[$group]) || array_is_list($body[$group]))) {
                $errors[$group] = 'Debe ser un objeto';
            }
        }
        if (is_array($body['destinatario'] ?? null)) {
            foreach (array_diff(array_keys($body['destinatario']), ['celular']) as $field) {
                $errors["destinatario.{$field}"] = 'Del destinatario solo se puede corregir el celular';
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
