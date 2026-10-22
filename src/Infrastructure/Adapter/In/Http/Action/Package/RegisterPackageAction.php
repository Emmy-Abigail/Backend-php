<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Package;

use App\Application\Exception\AssignmentUnavailable;
use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\PackageRegistrationConflict;
use App\Application\Exception\PricingUnavailable;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\RegisterPackageCommand;
use App\Application\Port\In\Package\RegisterPackageUseCase;
use App\Domain\Package\InvalidPackageData;
use App\Domain\Package\PackageDetails;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RegisterPackageAction
{
    public function __construct(private RegisterPackageUseCase $useCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $operator = $request->getAttribute('authUser');
        if (!$operator instanceof AuthenticatedUser) {
            return $this->json($response, ['error' => 'no_autenticado', 'message' => 'Token no proporcionado'], 401);
        }
        if ($operator->role !== 'OPERADOR' || $operator->idSede === null || $operator->mustChangePassword) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para registrar paquetes'], 403);
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_is_list($body)) {
            return $this->json($response, ['error' => 'cuerpo_invalido', 'message' => 'El cuerpo debe ser un objeto JSON valido'], 400);
        }

        try {
            $command = new RegisterPackageCommand($operator, PackageDetails::fromArray($body));
            $result = $this->useCase->execute($command);
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
            return $this->json($response, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para registrar paquetes'], 403);
        }

        return $this->json($response, $result->toArray(), 201);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
