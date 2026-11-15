<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Dashboard;

use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Dashboard\GetDashboardMetricsQuery;
use App\Application\Port\In\Dashboard\GetDashboardMetricsUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/v1/dashboard/metrics?desde=AAAA-MM-DD&hasta=AAAA-MM-DD[&id_sede=N] */
final readonly class GetDashboardMetricsAction
{
    public function __construct(private GetDashboardMetricsUseCase $useCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('authUser');
        if (!$user instanceof AuthenticatedUser) {
            return DashboardJson::write($response, ['error' => 'no_autenticado', 'message' => 'Token no proporcionado'], 401);
        }

        $params = DashboardQueryParams::fromQuery($request->getQueryParams());
        if ($params->errors !== []) {
            return DashboardJson::invalidParams($response, $params);
        }

        try {
            $result = $this->useCase->execute(new GetDashboardMetricsQuery(
                $user,
                (string) $params->from,
                (string) $params->to,
                $params->sedeId,
            ));
        } catch (UserNotAllowed) {
            return DashboardJson::write($response, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para ver el dashboard'], 403);
        } catch (InvalidCatalogReference $exception) {
            return DashboardJson::write($response, [
                'error' => 'parametros_invalidos',
                'message' => $exception->getMessage(),
                'campos' => ['id_sede' => $exception->getMessage()],
            ], 422);
        }

        return DashboardJson::write($response, $result->toArray(), 200);
    }
}
